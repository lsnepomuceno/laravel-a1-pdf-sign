<?php

namespace LSNepomuceno\LaravelA1PdfSign\Support;

use Illuminate\Process\Factory;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessRunTimeException;

/**
 * The package's single point of shell-out.
 *
 * An arch rule asserts nothing else in src/ touches the process factory or the
 * exec family, so every external command is auditable here. Only the legacy
 * certificate reader and the signature validator still need it; see
 * docs/decisions/0001-openssl-native-with-cli-fallback.md.
 *
 * It runs through Laravel's process factory rather than Symfony's Process
 * directly: the behaviour is identical — the factory builds the same object —
 * but a host application can fake it in its own tests, which is impossible
 * when the class is instantiated inline.
 */
final readonly class ProcessRunner
{
    public function __construct(private Factory $factory) {}

    /**
     * @throws ProcessRunTimeException
     */
    public function run(string $command, bool $usePathEnv = false): string
    {
        // newPendingProcess() rather than the factory's __call proxy, which
        // static analysis cannot follow.
        $result = $this->factory
            ->newPendingProcess()
            ->env($usePathEnv ? ['PATH' => (string) getenv('PATH')] : [])
            ->run($command);

        if ($result->failed()) {
            throw new ProcessRunTimeException($result->errorOutput());
        }

        return $result->output();
    }
}
