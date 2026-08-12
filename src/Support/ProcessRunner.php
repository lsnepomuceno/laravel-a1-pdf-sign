<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Support;

use Illuminate\Process\Factory;
use LogicException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\{MissingBinaryException,
    ProcessRunTimeException,
    ProcessUnavailableException};

/**
 * The package's single point of shell-out.
 *
 * An arch rule asserts nothing else in src/ touches the process factory or the
 * exec family, so every external command is auditable here. Only the legacy
 * certificate reader and the signature validator still need it; see
 * docs/decisions/0001-openssl-native-with-cli-fallback.md.
 *
 * It runs through Laravel's process factory rather than Symfony's Process
 * directly: the behaviour is identical (the factory builds the same object)
 * but a host application can fake it in its own tests, which is impossible
 * when the class is instantiated inline.
 *
 * **Being unable to run is not the same as running and failing**, and this is
 * the only place that can tell them apart. Downstream, `SignatureVerifier`
 * reads a non-zero exit as "this signature does not verify", which is correct
 * for a real verdict and catastrophic for an environment problem: a missing
 * binary used to make every signature report as invalid, silently. So the two
 * conditions that mean "no verdict was reached" raise their own exceptions
 * before the command is ever built.
 */
final readonly class ProcessRunner
{
    public function __construct(private Factory $factory) {}

    /**
     * @throws ProcessRunTimeException When the command ran and failed, which is
     *                                 a result.
     * @throws ProcessUnavailableException When PHP cannot start a process.
     * @throws MissingBinaryException When the command's binary is not on PATH.
     */
    public function run(string $command, bool $usePathEnv = false): string
    {
        $this->guardProcessesAreAvailable();
        $this->guardBinaryExists($command);

        try {
            // newPendingProcess() rather than the factory's __call proxy, which
            // static analysis cannot follow.
            $result = $this->factory
                ->newPendingProcess()
                ->env($usePathEnv ? ['PATH' => (string) getenv('PATH')] : [])
                ->run($command);
        } catch (LogicException $exception) {
            // Symfony's Process raises a bare LogicException when proc_open is
            // missing. The guard above catches the common form of that; this
            // catches the platforms where the function exists and the process
            // layer still refuses, and stops it arriving as an exception from
            // somebody else's namespace.
            if (str_contains($exception->getMessage(), 'proc_open')) {
                throw new ProcessUnavailableException();
            }

            throw $exception;
        }

        if ($result->failed()) {
            throw new ProcessRunTimeException($result->errorOutput());
        }

        return $result->output();
    }

    /**
     * @throws ProcessUnavailableException
     */
    private function guardProcessesAreAvailable(): void
    {
        // function_exists() returns false for a function in disable_functions,
        // which is exactly the case worth naming.
        if (! function_exists('proc_open')) {
            throw new ProcessUnavailableException();
        }
    }

    /**
     * @throws MissingBinaryException
     */
    private function guardBinaryExists(string $command): void
    {
        // A faked factory runs nothing, so whether the binary exists is not a
        // question about the host and asking it would defeat Process::fake(),
        // which this class exists to keep working.
        if ($this->factory->isRecording()) {
            return;
        }

        $binary = $this->binaryOf($command);

        // A command built by this package always begins with a bare program
        // name. Anything else, a path or an empty string, is left to the
        // process layer rather than guessed at here.
        if ($binary === null) {
            return;
        }

        foreach (explode(PATH_SEPARATOR, $this->searchPath()) as $directory) {
            if ($directory !== '' && is_executable(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binary)) {
                return;
            }
        }

        throw new MissingBinaryException($binary);
    }

    /**
     * The program a command invokes, or null when it is not a bare name.
     */
    private function binaryOf(string $command): ?string
    {
        $first = strtok(trim($command), " \t");

        if ($first === false || str_contains($first, DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $first;
    }

    /**
     * Where to look for a program.
     *
     * $usePathEnv changes what the child process is given, not where the
     * program is found, so both cases search the same list.
     */
    private function searchPath(): string
    {
        $path = (string) getenv('PATH');

        // A PATH-less environment is not a missing binary, and treating it as
        // one would raise on a host where the process layer would have found
        // the program anyway.
        return $path === '' ? '/usr/local/bin' . PATH_SEPARATOR . '/usr/bin' . PATH_SEPARATOR . '/bin' : $path;
    }
}
