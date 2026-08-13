<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureTransport;

/**
 * The diagnostic, and what it refuses to do.
 *
 * #269 established that a missing `openssl` binary made validation report every
 * signature as invalid, in silence. That is fixed where it happens, which is
 * necessary and reactive: the operator still learns about it from a signature
 * that came back wrong. This answers the same question before anything is
 * signed.
 */
it('reports a healthy environment and exits zero', function () {
    // Asserted against the whole output rather than through
    // expectsOutputToContain(), which matches one line at a time and cannot see
    // a report written as several.
    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('a1-pdf-sign:check'))->toBe(0)
        ->and(Artisan::output())
        ->toContain('ext-openssl')
        ->toContain('openssl binary')
        ->toContain('temporary directory')
        ->toContain('This environment can sign and validate.');
});

it('exits non-zero when the openssl binary is missing, so a pipeline can use it', function () {
    // Faked rather than uninstalled: the binary is present in the image, and
    // the point is what the command does when it is not.
    Process::fake(['openssl*' => Process::result(exitCode: 127, errorOutput: 'not found')]);

    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('a1-pdf-sign:check'))->toBe(1)
        ->and(Artisan::output())
        ->toContain('[NO] openssl binary')
        ->toContain('cannot sign or validate');
});

it('does not reach the network unless it is asked to', function () {
    // Invariant 9 keeps network access behind the injected transport, and a
    // diagnostic that reached a third party by default would make every artisan
    // call do it too.
    //
    // Asserted through the output rather than with a spy: the line exists only
    // when the authority is contacted, so its absence is the statement.
    config()->set('a1-pdf-sign.signature.timestamp.url', 'https://tsa.example/tsr');

    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('a1-pdf-sign:check'))->toBe(0)
        ->and(Artisan::output())->not->toContain('timestamp authority');
});

it('reaches the authority through the contract when asked', function () {
    config()->set('a1-pdf-sign.signature.timestamp.url', 'https://tsa.example/tsr');

    // Through the contract, so this cannot become a second place that opens a
    // connection, and so the test touches no real authority.
    app()->instance(SignatureTransport::class, new class implements SignatureTransport {
        public function timestamp(string $url, ?string $username = null, ?string $password = null): callable
        {
            return static fn(string $request): string => 'a token';
        }

        public function ocsp(): callable
        {
            return static fn(string $url, string $request): false => false;
        }

        public function crl(): callable
        {
            return static fn(string $url): false => false;
        }
    });

    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('a1-pdf-sign:check', ['--tsa' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('timestamp authority')
        ->toContain('answered');
});

it('says nothing a person would mind pasting into an issue', function () {
    // The output of this command is what ends up in a bug report.
    config()->set('a1-pdf-sign.signature.timestamp.password', 'hunter2');

    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('a1-pdf-sign:check'))->toBe(0)
        ->and(Artisan::output())->not->toContain('hunter2');
});
