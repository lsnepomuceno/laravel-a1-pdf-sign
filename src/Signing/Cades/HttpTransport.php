<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Cades;

use Illuminate\Contracts\Config\Repository as Config;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessRunTimeException;

/**
 * The HTTP the signature primitives deliberately do not do.
 *
 * tc-lib-pdf-sign keeps its codecs pure and takes transports as callables, so
 * the host owns networking — and therefore owns the SSRF surface. Every URL
 * reached here comes from configuration or from an extension inside the
 * signer's own certificate, never from the document being signed.
 */
final readonly class HttpTransport
{
    public function __construct(private Config $config) {}

    /**
     * Posts a DER TimeStampReq and returns the DER TimeStampResp.
     *
     * @return callable(string): string
     */
    public function timestamp(string $url, ?string $username = null, ?string $password = null): callable
    {
        $timeout = (int) $this->config->get('a1-pdf-sign.signature.timestamp.timeout', 20);

        return function (string $request) use ($url, $username, $password, $timeout): string {
            $headers = ['Content-Type: application/timestamp-query'];

            if ($username !== null && $username !== '') {
                $headers[] = 'Authorization: Basic ' . base64_encode("{$username}:{$password}");
            }

            return $this->post($url, $request, $headers, $timeout);
        };
    }

    /**
     * Posts a DER OCSP request and returns the DER response, or false to skip.
     *
     * @return callable(string, string): (string|false)
     */
    public function ocsp(): callable
    {
        $timeout = (int) $this->config->get('a1-pdf-sign.signature.ltv.timeout', 10);

        return function (string $url, string $request) use ($timeout): string|false {
            try {
                return $this->post($url, $request, ['Content-Type: application/ocsp-request'], $timeout);
            } catch (ProcessRunTimeException) {
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
        $timeout = (int) $this->config->get('a1-pdf-sign.signature.ltv.timeout', 10);

        return function (string $url) use ($timeout): string|false {
            $context = stream_context_create(['http' => ['timeout' => $timeout], 'https' => ['timeout' => $timeout]]);

            return @file_get_contents($url, false, $context);
        };
    }

    /**
     * @param  array<int, string>  $headers
     *
     * @throws ProcessRunTimeException
     */
    private function post(string $url, string $body, array $headers, int $timeout): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false || $response === '') {
            throw new ProcessRunTimeException("no response from {$url}");
        }

        return $response;
    }
}
