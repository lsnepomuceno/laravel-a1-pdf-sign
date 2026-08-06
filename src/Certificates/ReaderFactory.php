<?php

namespace LSNepomuceno\LaravelA1PdfSign\Certificates;

use Illuminate\Contracts\Config\Repository as Config;
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Contracts\CertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Support\ProcessRunner;

/**
 * Picks the certificate reader.
 *
 * Native is the default. The CLI is only reached when legacy mode is on,
 * because that is the single capability ext-openssl cannot provide — reading
 * RC2/40-bit bundles under OpenSSL 3.x. See ARCHITECTURE-V2.md §3a.
 */
final readonly class ReaderFactory
{
    public function __construct(
        private Config $config,
        private CertificateParser $parser,
        private ProcessRunner $processes,
        private A1PdfSign $paths,
    ) {}

    public function make(?bool $legacy = null, ?bool $usePathEnv = null): CertificateReader
    {
        $legacy ??= (bool) $this->config->get('a1-pdf-sign.certificate.legacy', false);
        $usePathEnv ??= (bool) $this->config->get('a1-pdf-sign.certificate.use_path_env', false);

        return $legacy
            ? new OpenSslCliCertificateReader($this->parser, $this->processes, $this->paths, true, $usePathEnv)
            : new NativeCertificateReader($this->parser);
    }
}
