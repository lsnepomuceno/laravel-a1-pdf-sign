<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Validation;

use LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;
use LSNepomuceno\LaravelA1PdfSign\Support\Pem;

/**
 * The certificate authorities an application chooses to trust.
 *
 * **The package does not ship any, and will not.** A bundled trust store is a
 * security-relevant artefact that goes stale between releases, and shipping one
 * would make the package's release cadence the thing that decides whose
 * signatures you accept. Which authorities to trust is a policy decision and it
 * stays with the application; verifying a chain against the ones it named is
 * mechanism, and that is what this and TrustVerifier provide.
 *
 * For ICP-Brasil, the current chain is published by the ITI. Fetch it, keep it
 * where your application keeps its configuration, and hand it here.
 *
 * See docs/decisions/0016-trust-is-the-applications-policy.md.
 */
final readonly class TrustStore
{
    /**
     * The extensions a CA bundle ships under. All three hold PEM.
     */
    private const array EXTENSIONS = ['pem', 'crt', 'cer'];

    /**
     * @param  list<string>  $certificates  Root certificates in PEM form.
     */
    private function __construct(
        public array $certificates,
    ) {}

    /**
     * One or more certificates concatenated, which is how a CA bundle ships.
     */
    public static function fromPem(string $contents): self
    {
        return new self(array_values(array_unique(Pem::certificates($contents))));
    }

    /**
     * @throws FileNotFoundException
     */
    public static function fromFile(string $path): self
    {
        return self::fromPem(Files::read($path));
    }

    /**
     * Every .pem, .crt and .cer in a directory, concatenated.
     *
     * A directory is not handed to OpenSSL as a CA path on purpose: that form
     * requires the hashed symlinks c_rehash creates, and a directory of plain
     * files silently verifies nothing. Measured on 2026-08-10, it returns false
     * for a certificate whose issuer is sitting right there.
     *
     * @throws FileNotFoundException
     */
    public static function fromDirectory(string $path): self
    {
        $directory = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $files = [];

        // One call per extension rather than the brace form. GLOB_BRACE is a GNU
        // extension, so PHP leaves the constant undefined on musl: on
        // php:8.4-alpine, one of the most common images this package runs in,
        // "*.{pem,crt,cer}" fatals on the constant before glob() is reached.
        foreach (self::EXTENSIONS as $extension) {
            $found = glob($directory . '*.' . $extension);

            if ($found === false) {
                throw new FileNotFoundException($path);
            }

            $files = [...$files, ...$found];
        }

        // Grouped by extension otherwise, which would make the bundle's order
        // depend on which extension the authority happened to publish under.
        sort($files);

        $contents = '';

        foreach ($files as $file) {
            $contents .= Files::read($file) . "\n";
        }

        return self::fromPem($contents);
    }

    /**
     * Trusting nothing, which is not the same as not checking.
     *
     * A validation run against an empty store reports every signature as
     * untrusted; a run with no store at all reports trust as unknown.
     */
    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->certificates === [];
    }

    public function count(): int
    {
        return count($this->certificates);
    }

    /**
     * The roots as one bundle, which is the form OpenSSL takes.
     */
    public function toPem(): string
    {
        return implode("\n", $this->certificates);
    }
}
