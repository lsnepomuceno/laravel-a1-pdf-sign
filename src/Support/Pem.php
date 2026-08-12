<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Support;

/**
 * PEM armour: reading certificates out of it, and putting DER into it.
 *
 * The same three lines of `preg_match_all` over
 * `-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----` had been written
 * four times, in the CAdES builder, the security store writer, the trust store
 * and a spike. Four copies of a pattern is four places to get the `s` modifier
 * wrong, and the one that gets it wrong reads a single certificate out of a
 * bundle and calls it the chain.
 */
final readonly class Pem
{
    /**
     * The armour a certificate opens with, which is how PEM is told apart from
     * DER and PKCS#12 by content rather than by file extension
     * (docs/decisions/0007-pem-second-entry-one-pipeline.md).
     */
    public const string CERTIFICATE_MARKER = '-----BEGIN CERTIFICATE-----';

    /**
     * Every certificate in a bundle, in the order it appears.
     *
     * The `s` modifier is what makes this read a certificate rather than a
     * line: the base64 body is wrapped, so a pattern without it matches
     * nothing at all.
     *
     * @return list<string>
     */
    public static function certificates(string $contents): array
    {
        preg_match_all(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            $contents,
            $found,
        );

        return $found[0];
    }

    public static function hasCertificate(string $contents): bool
    {
        return str_contains($contents, self::CERTIFICATE_MARKER);
    }

    /**
     * The DER bytes inside one PEM certificate.
     *
     * Null when the body is not valid base64, which the caller reports in its
     * own terms: a bundle with a corrupt entry is a certificate problem in one
     * place and a parsing problem in another.
     */
    public static function toDer(string $pem): ?string
    {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s/', '', $pem);
        $der = base64_decode($body ?? '', true);

        return $der === false || $der === '' ? null : $der;
    }

    /**
     * DER wrapped back into armour, wrapped at 64 characters as RFC 7468 asks.
     */
    public static function fromDer(string $der): string
    {
        return self::CERTIFICATE_MARKER . "\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END CERTIFICATE-----\n";
    }
}
