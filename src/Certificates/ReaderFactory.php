<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Certificates;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Contracts\CertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Support\ProcessRunner;

/**
 * Picks the certificate reader.
 *
 * Native is the default. The CLI is only reached when legacy mode is on,
 * because that is the single capability ext-openssl cannot provide: reading
 * RC2/40-bit bundles under OpenSSL 3.x. See docs/decisions/0001-openssl-native-with-cli-fallback.md.
 */
final readonly class ReaderFactory
{
    /**
     * The temp path comes from the A1PdfSign contract, but resolving it here
     * would close a cycle: the manager depends on this factory. The container
     * is held instead and the contract resolved only when the CLI reader is
     * actually built, which is the rare path.
     */
    public function __construct(
        private Config $config,
        private CertificateParser $parser,
        private ProcessRunner $processes,
        private Container $container,
    ) {}

    public function make(?bool $legacy = null, ?bool $usePathEnv = null): CertificateReader
    {
        $legacy ??= (bool) $this->config->get('a1-pdf-sign.certificate.legacy', false);
        $usePathEnv ??= (bool) $this->config->get('a1-pdf-sign.certificate.use_path_env', false);

        return $legacy
            ? new OpenSslCliCertificateReader(
                $this->parser,
                $this->processes,
                $this->container->make(A1PdfSign::class),
                true,
                $usePathEnv,
            )
            : new NativeCertificateReader($this->parser);
    }
}
