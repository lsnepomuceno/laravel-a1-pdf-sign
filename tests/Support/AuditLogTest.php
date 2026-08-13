<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Enums\SigningEvent;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Support\SigningLog;
use Psr\Log\AbstractLogger;

/**
 * The audit trail, and what it refuses to write down.
 *
 * The allowlist is the feature. This package handles PKCS#12 bundles, private
 * keys and passwords, and `#[\SensitiveParameter]` keeps a value out of a stack
 * trace while having nothing to say about a line written to disk
 * (docs/decisions/0035-the-audit-trail-is-opt-in.md).
 */

/**
 * A logger that keeps every line, so a test can look at what was written.
 *
 * Returns the PSR-3 type as well as the anonymous class, so it can be passed to
 * SigningLog and still have its recorded lines read.
 *
 * @return AbstractLogger&object{lines: list<array{level: mixed, message: string, context: array<mixed>}>}
 */
function recordingLogger(): AbstractLogger
{
    return new class extends AbstractLogger {
        /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
        public array $lines = [];

        /**
         * @param  array<mixed>  $context  Widened to match PSR-3's own
         *                                 signature, which PHPStan requires
         *                                 for contravariance.
         */
        public function log($level, string|\Stringable $message, array $context = []): void
        {
            $this->lines[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
        }
    };
}

it('writes nothing when no logger was given', function () {
    // The default. A package that logs unasked fills somebody's disk.
    $log = new SigningLog();

    $log->record(SigningEvent::SignatureApplied, ['profile' => 'pades-b-b']);

    // Nothing to assert against but the absence of a crash: with no logger
    // there is no sink, which is the whole point.
    expect(true)->toBeTrue();
});

it('records the event and what the allowlist permits', function () {
    $logger = recordingLogger();

    new SigningLog($logger)->record(SigningEvent::SignatureApplied, [
        'profile' => 'pades-b-lt',
        'signer' => 'Test Certificate',
    ]);

    expect($logger->lines)->toHaveCount(1)
        ->and($logger->lines[0]['message'])->toBe('signature.applied')
        ->and($logger->lines[0]['context'])->toBe([
            'event' => 'signature.applied',
            'profile' => 'pades-b-lt',
            'signer' => 'Test Certificate',
        ]);
});

it('drops everything the allowlist does not name', function (string $key) {
    // A denylist is how the next property added to a data object ends up in a
    // log file. This asserts the shape rather than the list.
    $logger = recordingLogger();

    new SigningLog($logger)->record(SigningEvent::SignatureApplied, [$key => 'a secret']);

    expect($logger->lines[0]['context'])->toBe(['event' => 'signature.applied']);
})->with([
    'password',
    'certificate',
    'private_key',
    'pfx',
    'path',
    'document',
    'contents',
    'cms',
    'anything_added_later',
]);

it('drops an object arriving under a permitted name', function () {
    // Scalars only: an object could carry a key into whatever formats the line,
    // and the format is the host's choice rather than ours.
    $logger = recordingLogger();

    new SigningLog($logger)->record(SigningEvent::SignatureApplied, [
        'signer' => new stdClass(),
    ]);

    expect($logger->lines[0]['context'])->toBe(['event' => 'signature.applied']);
});

it('writes no secret when a real document is signed', function () {
    // The end-to-end statement, rather than a statement about the filter.
    $logger = recordingLogger();

    app()->instance(SigningLog::class, new SigningLog($logger));

    [$pfxPath, $password] = debugCertificate();

    A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->info(name: 'Lucas')
        ->sign();

    $written = json_encode($logger->lines, JSON_THROW_ON_ERROR);

    expect($logger->lines)->not->toBeEmpty()
        ->and($written)->toContain('signature.applied')
        ->and($written)->not->toContain($password)
        ->and($written)->not->toContain($pfxPath)
        ->and($written)->not->toContain('BEGIN PRIVATE KEY')
        ->and($written)->not->toContain('BEGIN CERTIFICATE');
});

it('names the profile and the field a signature used', function () {
    $logger = recordingLogger();

    app()->instance(SigningLog::class, new SigningLog($logger));

    [$pfxPath, $password] = debugCertificate();

    A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    expect($logger->lines[0]['context']['profile'])->toBe('pades-b-b')
        ->and($logger->lines[0]['context']['field'])->toBe('Signature1');
});
