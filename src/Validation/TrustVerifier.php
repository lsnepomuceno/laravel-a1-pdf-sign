<?php

namespace LSNepomuceno\LaravelA1PdfSign\Validation;

use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Support\TemporaryFile;

/**
 * Whether a signature's certificate chains to an authority the application trusts.
 *
 * **OpenSSL does the path validation, not this class.** Walking the chain by
 * hand would check that each certificate was signed by the next, which
 * `ChainBuilder` already does, and would silently skip everything else path
 * validation means: the validity window of each intermediate, basicConstraints
 * saying a certificate may act as a CA at all, key usage, name constraints and
 * path length. A hand-rolled check would accept a chain that a reader rejects,
 * which is the worst direction for this particular answer to be wrong in.
 *
 * `openssl_x509_checkpurpose()` takes the trusted roots as a file and the
 * intermediates as another, so both are written out and deleted however the
 * call ends (docs/decisions/0003-temporary-files-outside-the-package.md).
 *
 * @internal
 */
final readonly class TrustVerifier
{
    public function __construct(
        private A1PdfSign $paths,
    ) {}

    /**
     * @param  list<string>  $chain  The signature's certificates, leaf first, as
     *                               ChainBuilder ordered them.
     */
    public function trusts(TrustStore $store, array $chain): bool
    {
        if ($chain === [] || $store->isEmpty()) {
            return false;
        }

        $leaf = $chain[0];
        $intermediates = array_slice($chain, 1);

        $directory = $this->paths->tempPath();

        return TemporaryFile::with(
            $directory,
            '.pem',
            $store->toPem(),
            function (TemporaryFile $roots) use ($directory, $leaf, $intermediates): bool {
                // An empty file is not "no intermediates" to OpenSSL, it is a
                // file with no certificates in it, which it warns about and
                // refuses. A self-signed signer has no intermediates at all.
                if ($intermediates === []) {
                    return $this->checks($leaf, $roots->path, null);
                }

                // The intermediates are untrusted material: they help build the
                // path and are not themselves a reason to accept it.
                return TemporaryFile::with(
                    $directory,
                    '.pem',
                    implode("\n", $intermediates),
                    fn(TemporaryFile $untrusted): bool => $this->checks($leaf, $roots->path, $untrusted->path),
                );
            },
        );
    }

    /**
     * openssl_x509_checkpurpose returns true, false, or -1 for its own failure.
     * Only true is a pass, so the error case cannot be read as one.
     */
    private function checks(string $leaf, string $roots, ?string $untrusted): bool
    {
        return openssl_x509_checkpurpose($leaf, X509_PURPOSE_ANY, [$roots], $untrusted) === true;
    }
}
