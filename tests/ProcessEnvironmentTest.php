<?php

declare(strict_types=1);

use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\MissingBinaryException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessRunTimeException;
use LSNepomuceno\LaravelA1PdfSign\Support\ProcessRunner;

/**
 * Being unable to run a command, against running one that fails.
 *
 * The two used to be the same thing. `Validation\SignatureVerifier` caught
 * every throwable and returned false, on the correct reasoning that a non-zero
 * exit from `openssl smime -verify` means the signature does not verify. The
 * catch was wider than the reasoning, so a missing binary, `proc_open` in
 * `disable_functions`, and an unwritable temporary directory all arrived at the
 * same place and left as "invalid".
 *
 * Measured before it was fixed, on `samples/pades-b-b.pdf`, changing nothing
 * but the environment:
 *
 * | openssl present            | isValid=true  |
 * | openssl binary removed     | isValid=false |
 * | proc_open disabled         | isValid=false |
 *
 * A wrong answer, not a degraded one: the caller cannot tell it from a tampered
 * document, and the natural response is to reject something legitimate.
 */
it('raises rather than reporting a verdict when the binary is not there', function () {
    expect(fn() => app(ProcessRunner::class)->run('a1-pdf-sign-no-such-binary --version'))
        ->toThrow(MissingBinaryException::class);
});

it('names the binary it could not find, since that is the whole point', function () {
    try {
        app(ProcessRunner::class)->run('a1-pdf-sign-no-such-binary --version');
    } catch (MissingBinaryException $exception) {
        expect($exception->binary)->toBe('a1-pdf-sign-no-such-binary')
            ->and($exception->getMessage())->toContain('was not found on the PATH');

        return;
    }

    // Reached only if nothing was thrown, which is the regression this file
    // exists for.
    expect(false)->toBeTrue();
});

it('still reports a command that ran and failed as a failure, not as an environment problem', function () {
    // The distinction the whole change turns on. `false` exits non-zero and is
    // on every PATH, so this is a command that ran.
    expect(fn() => app(ProcessRunner::class)->run('false'))
        ->toThrow(ProcessRunTimeException::class);
});

it('runs a command that exists, unchanged', function () {
    expect(app(ProcessRunner::class)->run('openssl version'))->toContain('OpenSSL');
});

it('does not check the PATH when the factory is faked, which would defeat the fake', function () {
    // Support\ProcessRunner is built on Illuminate\Process\Factory precisely so
    // a host application can Process::fake() it, and its docblock says so. A
    // guard that inspected the real PATH would make that promise false for
    // every command the host does not happen to have installed.
    Process::fake([
        '*' => Process::result(output: 'faked'),
    ]);

    // The assertion that matters is that nothing was thrown; the fake's output
    // carries a trailing newline, as a real process would.
    expect(trim(app(ProcessRunner::class)->run('a1-pdf-sign-no-such-binary --version')))
        ->toBe('faked');
});

it('translates the process layer refusing to spawn into an exception of its own', function () {
    // Symfony's Process raises a bare LogicException when proc_open is missing.
    // Reaching the caller as somebody else's exception class is the thing
    // docs/decisions/0008-exceptions-name-the-real-fault.md rules out.
    //
    // proc_open cannot be disabled from inside a running process, so the
    // condition is produced at the factory rather than in php.ini.
    $factory = new class extends Factory {
        #[Override]
        public function newPendingProcess(): never
        {
            throw new LogicException(
                'The Process class relies on proc_open, which is not available on your PHP installation.',
            );
        }
    };

    expect(fn() => new ProcessRunner($factory)->run('openssl version'))
        ->toThrow(LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessUnavailableException::class);
});
