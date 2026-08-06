<?php

namespace LSNepomuceno\LaravelA1PdfSign\Support;

use LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessRunTimeException;
use Symfony\Component\Process\Process;

/**
 * The package's single point of shell-out.
 *
 * An arch rule asserts nothing else in src/ touches Symfony\Component\Process
 * or the exec family, so every external command is auditable here. Only the
 * legacy certificate reader and the signature validator still need it; see
 * ARCHITECTURE-V2.md §3a.
 */
final class ProcessRunner
{
    /**
     * @throws ProcessRunTimeException
     */
    public function run(string $command, bool $usePathEnv = false): string
    {
        $process = Process::fromShellCommandline(
            command: $command,
            env: $usePathEnv ? ['PATH' => getenv('PATH')] : null,
        );

        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessRunTimeException($process->getErrorOutput());
        }

        return $process->getOutput();
    }
}
