<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Adapters;

use Illuminate\Http\Client\Factory;
use LSNepomuceno\Signet\Config\{LtvConfig, SigningConfig, TimestampConfig};
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Exceptions\SignatureTransportException;
use SensitiveParameter;
use Throwable;

/**
 * The TSA, OCSP and CRL client, on Laravel's HTTP client.
 *
 * Invariant 9 keeps network access behind this contract because the host
 * application owns that SSRF surface. **In a Laravel application, owning it
 * means owning it with Laravel's tools**: `Http::fake()`,
 * `Http::preventStrayRequests()`, the application's own middleware, proxy, CA
 * bundle and logging. signet-pdf's own implementation is built on
 * `symfony/http-client`, which sits outside all of that
 * (docs/decisions/0039-the-core-lives-in-signet-pdf.md).
 *
 * Every URL reached here comes from configuration or from an extension inside
 * the signer's own certificate, never from the document being signed.
 *
 * The retry budget and the timeouts are the engine's, read from the same
 * config objects, and the two policies differ on purpose: a timestamp
 * authority failing fails the signature, while a revocation responder failing
 * only degrades the profile.
 */
final readonly class IlluminateSignatureTransport implements SignatureTransport
{
    /**
     * The statuses worth trying again, for any method.
     *
     * Every request to a timestamp authority is a POST, and a retry policy
     * that only covers idempotent methods would drop the retry on exactly the
     * call it exists for: a TSA having a bad minute used to fail a whole
     * signature. Retrying is safe because both bodies are idempotent in
     * practice: a TimeStampReq asks for a token over a digest the caller
     * already holds, and an OCSP request asks a question. Neither creates
     * anything at the far end.
     *
     * @var list<int>
     */
    private const array RETRYABLE = [0, 423, 425, 429, 500, 502, 503, 504, 507, 510];

    public function __construct(
        private Factory $http,
        private SigningConfig $config = new SigningConfig(),
    ) {}

    /**
     * Posts a DER TimeStampReq and returns the DER TimeStampResp.
     *
     * @return callable(string): string
     */
    #[\Override]
    public function timestamp(
        string $url,
        ?string $username = null,
        #[SensitiveParameter]
        ?string $password = null,
    ): callable {
        $timestamp = $this->config->timestamp;

        return function (string $request) use ($url, $username, $password, $timestamp): string {
            return $this->post($url, $request, 'application/timestamp-query', $timestamp, $username, $password);
        };
    }

    /**
     * Posts a DER OCSP request and returns the DER response, or false to skip.
     *
     * @return callable(string, string): (string|false)
     */
    #[\Override]
    public function ocsp(): callable
    {
        $ltv = $this->config->ltv;

        return function (string $url, string $request) use ($ltv): string|false {
            try {
                return $this->post($url, $request, 'application/ocsp-request', $ltv);
            } catch (SignatureTransportException) {
                // A responder being unreachable degrades the profile; it must
                // not fail the signature.
                return false;
            }
        };
    }

    /**
     * Fetches a CRL, or false to skip it.
     *
     * @return callable(string): (string|false)
     */
    #[\Override]
    public function crl(): callable
    {
        $ltv = $this->config->ltv;

        return function (string $url) use ($ltv): string|false {
            try {
                $response = $this->pending($ltv)->get($url);
            } catch (Throwable) {
                return false;
            }

            $body = $response->body();

            return $response->successful() && $body !== '' ? $body : false;
        };
    }

    /**
     * @throws SignatureTransportException
     */
    private function post(
        string $url,
        string $body,
        string $contentType,
        TimestampConfig|LtvConfig $policy,
        ?string $username = null,
        #[SensitiveParameter]
        ?string $password = null,
    ): string {
        $request = $this->pending($policy)->withBody($body, $contentType);

        if ($username !== null && $username !== '') {
            $request = $request->withBasicAuth($username, $password ?? '');
        }

        try {
            $response = $request->post($url);
        } catch (Throwable $exception) {
            throw new SignatureTransportException($url, $exception->getMessage(), $exception);
        }

        // The body is read whatever the status. RFC 3161 answers a rejection
        // with a TimeStampResp carrying the reason, and an authority that says
        // why it refused is more useful than a status code on its own.
        $contents = $response->body();

        if ($contents === '') {
            throw new SignatureTransportException($url, "empty response, HTTP {$response->status()}");
        }

        return $contents;
    }

    /**
     * A request carrying the policy's timeout and retry budget.
     *
     * `attempts` counts attempts and not retries, so a budget of 1 means try
     * once and do not retry, which is what `retry()` is given here minus
     * nothing: Laravel's first argument is the number of times to try.
     */
    private function pending(TimestampConfig|LtvConfig $policy): \Illuminate\Http\Client\PendingRequest
    {
        // createPendingRequest() rather than the factory's __call proxy,
        // which static analysis reads as a dynamic call to a static method.
        return $this->http
            ->createPendingRequest()
            ->timeout($policy->timeout)
            ->retry(
                max(1, $policy->attempts),
                $policy->backoff,
                // `throw: false` on the retry decision, not on the response:
                // a status this package handles itself must not raise out of
                // the client before it is read.
                fn(Throwable $exception, \Illuminate\Http\Client\PendingRequest $request): bool => self::retryable($exception),
                throw: false,
            );
    }

    /**
     * Whether a failure is the transient kind worth another attempt.
     */
    private static function retryable(Throwable $exception): bool
    {
        if (! $exception instanceof \Illuminate\Http\Client\RequestException) {
            // A connection failure carries no status, and it is the one most
            // worth retrying.
            return true;
        }

        return in_array($exception->response->status(), self::RETRYABLE, strict: true);
    }
}
