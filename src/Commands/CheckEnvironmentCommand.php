<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Commands;

use Illuminate\Console\Command;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureTransport;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\A1PdfSignException;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Support\ProcessRunner;
use Throwable;

/**
 * What this package needs from the environment, answered before anything is
 * signed.
 *
 * Every check here is a real failure this package has had. The sharpest is the
 * openssl binary: ext-openssl being loaded says nothing about the command-line
 * tool being installed, and without it validation used to report every
 * signature as invalid in silence. That is fixed at the point of failure, and
 * this answers the same question in advance.
 *
 * **The TSA check is opt-in.** Invariant 9 keeps network access behind the
 * injected transport, and a diagnostic that reached a third party by default
 * would make `artisan` calls do it too.
 *
 * **Nothing sensitive is printed.** The output of this command is what gets
 * pasted into an issue.
 */
class CheckEnvironmentCommand extends Command
{
    protected $signature = 'a1-pdf-sign:check
                                {--tsa : Also reach the configured timestamp authority}
        ';

    protected $description = 'Reports whether this environment can sign and validate';

    /** @var list<string> */
    private array $fatal = [];

    public function handle(ProcessRunner $processes): int
    {
        $this->line('Checking what laravel-a1-pdf-sign needs from this environment.', 'info');
        $this->newLine();

        $this->requirement('ext-openssl', extension_loaded('openssl'), 'PKCS#12 reading and CMS building');
        $this->requirement('ext-bcmath', extension_loaded('bcmath'), 'required by tc-lib-pdf through tc-lib-barcode');
        $this->requirement('proc_open', function_exists('proc_open'), 'validation shells out; often in disable_functions');

        $this->requirement(
            'openssl binary',
            $this->binaryExists($processes),
            'validation and legacy PFX. Separate from ext-openssl',
        );

        $this->optional(
            'ext-gd or ext-imagick',
            extension_loaded('gd') || extension_loaded('imagick'),
            'only needed to draw a visible seal',
        );

        $this->requirement('temporary directory', $this->temporaryDirectoryIsWritable(), 'every shell-out writes one');

        $this->optional(
            'memory_limit',
            $this->memoryLimitIsGenerous(),
            'signing peaks at roughly 20 MB plus twice the document',
        );

        if ($this->option('tsa') === true) {
            $this->timestampAuthority();
        }

        $this->newLine();

        if ($this->fatal !== []) {
            $this->line('This environment cannot sign or validate: ' . implode(', ', $this->fatal), 'error');

            return self::FAILURE;
        }

        $this->line('This environment can sign and validate.', 'info');

        return self::SUCCESS;
    }

    /**
     * Something without which the package cannot do its job.
     */
    private function requirement(string $name, bool $met, string $why): void
    {
        $this->line(sprintf('  %s %-22s %s', $met ? '[ok]' : '[NO]', $name, $why), $met ? 'info' : 'error');

        if (! $met) {
            $this->fatal[] = $name;
        }
    }

    /**
     * Something a host may legitimately not have.
     *
     * Reported and never fatal: a host that only signs invisibly needs no image
     * library, and exiting non-zero over it would make this command unusable in
     * a deployment pipeline, which is the one place it is worth running.
     */
    private function optional(string $name, bool $met, string $why): void
    {
        $this->line(sprintf('  %s %-22s %s', $met ? '[ok]' : '[--]', $name, $why), $met ? 'info' : 'comment');
    }

    private function binaryExists(ProcessRunner $processes): bool
    {
        try {
            // Through the runner, so it is faked with everything else and the
            // arch rule about processes still holds.
            $processes->run('openssl version');

            return true;
        } catch (A1PdfSignException) {
            return false;
        }
    }

    private function temporaryDirectoryIsWritable(): bool
    {
        try {
            return is_writable(A1PdfSign::tempPath());
        } catch (Throwable) {
            return false;
        }
    }

    private function memoryLimitIsGenerous(): bool
    {
        $limit = ini_get('memory_limit');

        if ($limit === false || $limit === '-1') {
            return true;
        }

        return $this->bytes($limit) >= 256 * 1024 * 1024;
    }

    private function bytes(string $limit): int
    {
        $value = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private function timestampAuthority(): void
    {
        $url = config('a1-pdf-sign.signature.timestamp.url');

        if (! is_string($url) || $url === '') {
            $this->optional('timestamp authority', false, 'none configured; pades-b-t and above need one');

            return;
        }

        try {
            // Through the contract, so this cannot become a second place that
            // opens a connection (invariant 9).
            app(SignatureTransport::class)->timestamp($url)('');

            $this->optional('timestamp authority', true, 'answered');
        } catch (Throwable $exception) {
            $this->optional('timestamp authority', false, 'did not answer: ' . $exception->getMessage());
        }
    }
}
