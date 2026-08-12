<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Cades;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureTransport;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\SignatureTransportException;
use Throwable;

/**
 * The HTTP the signature primitives deliberately do not do.
 *
 * tc-lib-pdf-sign keeps its codecs pure and takes transports as callables, so
 * the host owns networking, and therefore owns the SSRF surface. Every URL
 * reached here comes from configuration or from an extension inside the
 * signer's own certificate, never from the document being signed.
 *
 * The interface behind it exists so a test can substitute a local authority
 * and gate the profiles that need one
 * (docs/decisions/0027-the-transport-is-a-seam.md).
 *
 * **It goes through the framework's client**, not through `file_get_contents()`
 * with a stream context, which is what it used to do. That cost more than
 * elegance: a host could not `Http::fake()` it, could not apply its own proxy,
 * middleware or logging, and a timestamp authority having a bad minute failed
 * the signature outright because nothing retried. `Support\ProcessRunner` is
 * built on Laravel's process factory for exactly the same reason, and the
 * network path had not been given the same treatment.
 */
final readonly class HttpTransport implements SignatureTransport
{
    public function __construct(private Config $config, private Http $http) {}

    /**
     * Posts a DER TimeStampReq and returns the DER TimeStampResp.
     *
     * @return callable(string): string
     */
    public function timestamp(string $url, ?string $username = null, ?string $password = null): callable
    {
        $timeout = $this->intConfig('signature.timestamp.timeout', 20);
        $attempts = $this->intConfig('signature.timestamp.attempts', 3);
        $backoff = $this->intConfig('signature.timestamp.backoff', 200);

        return function (string $request) use ($url, $username, $password, $timeout, $attempts, $backoff): string {
            $headers = [];

            if ($username !== null && $username !== '') {
                $headers['Authorization'] = 'Basic ' . base64_encode("{$username}:{$password}");
            }

            return $this->post($url, $request, 'application/timestamp-query', $headers, $timeout, $attempts, $backoff);
        };
    }

    /**
     * Posts a DER OCSP request and returns the DER response, or false to skip.
     *
     * @return callable(string, string): (string|false)
     */
    public function ocsp(): callable
    {
        $timeout = $this->intConfig('signature.ltv.timeout', 10);
        $attempts = $this->intConfig('signature.ltv.attempts', 2);
        $backoff = $this->intConfig('signature.ltv.backoff', 100);

        return function (string $url, string $request) use ($timeout, $attempts, $backoff): string|false {
            try {
                return $this->post($url, $request, 'application/ocsp-request', [], $timeout, $attempts, $backoff);
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
    public function crl(): callable
    {
        $timeout = $this->intConfig('signature.ltv.timeout', 10);
        $attempts = $this->intConfig('signature.ltv.attempts', 2);
        $backoff = $this->intConfig('signature.ltv.backoff', 100);

        return function (string $url) use ($timeout, $attempts, $backoff): string|false {
            try {
                $response = $this->request($timeout, $attempts, $backoff)->get($url);
            } catch (Throwable) {
                return false;
            }

            return $response->successful() ? $response->body() : false;
        };
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws SignatureTransportException
     */
    private function post(
        string $url,
        string $body,
        string $contentType,
        array $headers,
        int $timeout,
        int $attempts,
        int $backoff,
    ): string {
        try {
            $response = $this->request($timeout, $attempts, $backoff)
                ->withHeaders($headers)
                ->withBody($body, $contentType)
                ->post($url);
        } catch (Throwable $exception) {
            throw new SignatureTransportException($url, $exception->getMessage(), $exception);
        }

        // The body is read whatever the status. RFC 3161 answers a rejection
        // with a TimeStampResp carrying the reason, and an authority that says
        // why it refused is more useful than a status code on its own. This is
        // what `ignore_errors` was doing in the stream context.
        $contents = $response->body();

        if ($contents === '') {
            throw new SignatureTransportException($url, "empty response, HTTP {$response->status()}");
        }

        return $contents;
    }

    /**
     * A request that retries transient failures.
     *
     * `throw: false` because a non-2xx is not necessarily a failure here: the
     * body still has to be read. Retrying is for connection problems and for
     * the statuses Laravel's client treats as retryable.
     */
    private function request(int $timeout, int $attempts, int $backoff): PendingRequest
    {
        // createPendingRequest() rather than the factory's __call proxy, which
        // static analysis cannot follow. Support\ProcessRunner uses
        // newPendingProcess() for the same reason.
        return $this->http
            ->createPendingRequest()
            ->timeout($timeout)
            ->retry(max(1, $attempts), max(0, $backoff), throw: false);
    }

    private function intConfig(string $key, int $default): int
    {
        $value = $this->config->get("a1-pdf-sign.{$key}", $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
