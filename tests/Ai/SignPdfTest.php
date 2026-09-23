<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\{Auth, Event, Gate, Storage};
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;
use LSNepomuceno\LaravelA1PdfSign\Agents\Ability;
use LSNepomuceno\LaravelA1PdfSign\Agents\ToolCallLedger;
use LSNepomuceno\LaravelA1PdfSign\Ai\Tools\SignPdf;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SigningCertificateResolver;
use LSNepomuceno\LaravelA1PdfSign\Events\DocumentSignedByAgent;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\SigningCertificateUnavailable;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Enums\SignatureProfile;

/**
 * The signing tool, called directly.
 *
 * Calling `handle()` is what the AI SDK does once a person has approved the
 * call, so these tests are the "after approval" half. The "before" half, the
 * run pausing and nothing being signed until somebody says yes, is proved
 * through the agent loop in AgentLoopTest.
 */

/**
 * A resolver handing back a real, throwaway certificate.
 */
function certificateResolver(): SigningCertificateResolver
{
    return new readonly class implements SigningCertificateResolver {
        public function resolve(): Certificate
        {
            return testCertificate();
        }
    };
}

/**
 * A resolver that fails the way a real one does when its store is down.
 */
function failingResolver(string $message): SigningCertificateResolver
{
    return new readonly class ($message) implements SigningCertificateResolver {
        public function __construct(private string $message) {}

        public function resolve(): Certificate
        {
            throw new RuntimeException($this->message);
        }
    };
}

/**
 * The tool's JSON answer, decoded.
 *
 * @return array<array-key, mixed>
 */
function answer(string $json): array
{
    return (array) json_decode($json, true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    Storage::fake('contracts');
    Storage::disk('contracts')->put('deal.pdf', (string) file_get_contents(resource('test.pdf')));
    config()->set('a1-pdf-sign.agents.disks', ['contracts']);

    Event::fake([DocumentSignedByAgent::class]);
});

it('signs the document and writes the copy beside it', function () {
    $result = answer(new SignPdf(certificateResolver())->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf'], 'call_1')));

    expect($result)->toMatchArray([
        'signed' => true,
        'status' => 'signed',
        'disk' => 'contracts',
        'path' => 'deal_signed.pdf',
        'source' => ['disk' => 'contracts', 'path' => 'deal.pdf'],
        'signer' => 'Test Certificate',
        'profile' => 'pades-b-b',
    ])->and($result['sha256'])->toBeString()->toHaveLength(64)
        ->and($result['revision_size'])->toBeGreaterThan(0);

    Storage::disk('contracts')->assertExists('deal_signed.pdf');

    $report = A1PdfSign::validate(A1PdfSign::fromDisk('contracts', 'deal_signed.pdf'));

    expect($report->isValid())->toBeTrue()
        ->and($report->count())->toBe(1)
        ->and($report->latest()?->signer()?->commonName)->toBe('Test Certificate');
});

it('leaves the original untouched', function () {
    $original = Storage::disk('contracts')->get('deal.pdf');

    new SignPdf(certificateResolver())->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf']));

    expect(Storage::disk('contracts')->get('deal.pdf'))->toBe($original)
        // Signing appends a revision, so the original is the signed copy's
        // prefix, byte for byte.
        ->and(str_starts_with((string) Storage::disk('contracts')->get('deal_signed.pdf'), (string) $original))->toBeTrue();
});

it('writes where the agent asked, on another open disk', function () {
    Storage::fake('archive');
    config()->set('a1-pdf-sign.agents.disks', ['contracts', 'archive']);

    $result = answer(new SignPdf(certificateResolver())->handle(new Request([
        'disk' => 'contracts',
        'path' => 'deal.pdf',
        'destination_disk' => 'archive',
        'destination_path' => '2026/deal.pdf',
    ])));

    expect($result)->toMatchArray(['disk' => 'archive', 'path' => '2026/deal.pdf']);

    Storage::disk('archive')->assertExists('2026/deal.pdf');
    Storage::disk('contracts')->assertMissing('deal_signed.pdf');
});

it('signs at the profile the agent asked for, and records the reason', function () {
    new SignPdf(certificateResolver())->handle(new Request([
        'disk' => 'contracts',
        'path' => 'deal.pdf',
        'profile' => 'legacy',
        'reason' => 'Contract approval',
    ]));

    $report = A1PdfSign::validate(A1PdfSign::fromDisk('contracts', 'deal_signed.pdf'));

    expect($report->latest()?->profile)->toBe(SignatureProfile::Legacy)
        ->and(Storage::disk('contracts')->get('deal_signed.pdf'))->toContain('Contract approval');
});

it('tells the application, with a receipt it can store', function () {
    Auth::setUser(new GenericUser(['id' => 42]));

    new SignPdf(certificateResolver())->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf'], 'call_1'));

    Event::assertDispatchedTimes(DocumentSignedByAgent::class, 1);
    Event::assertDispatched(DocumentSignedByAgent::class, fn(DocumentSignedByAgent $event) => $event->sourceDisk === 'contracts'
        && $event->sourcePath === 'deal.pdf'
        && $event->disk === 'contracts'
        && $event->path === 'deal_signed.pdf'
        && $event->toolCallId === 'call_1'
        && $event->userId === 42
        && $event->receipt?->signerName === 'Test Certificate'
        && $event->receipt->hash === hash('sha256', (string) Storage::disk('contracts')->get('deal_signed.pdf')));
});

it('says nobody was signed in rather than guessing', function () {
    new SignPdf(certificateResolver())->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf']));

    Event::assertDispatched(DocumentSignedByAgent::class, fn(DocumentSignedByAgent $event) => $event->userId === null
        && $event->toolCallId === null);
});

it('never overwrites a file, and says so to the model', function () {
    Storage::disk('contracts')->put('deal_signed.pdf', 'somebody else\'s file');

    $result = answer(new SignPdf(certificateResolver())->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf'])));

    expect($result)->toMatchArray(['signed' => false, 'status' => 'refused'])
        ->and($result['message'])->toContain('never overwrites')
        ->and(Storage::disk('contracts')->get('deal_signed.pdf'))->toBe('somebody else\'s file');

    Event::assertNotDispatched(DocumentSignedByAgent::class);
});

it('refuses a disk the application did not open', function () {
    Storage::fake('private');
    Storage::disk('private')->put('salaries.pdf', (string) file_get_contents(resource('test.pdf')));

    $result = answer(new SignPdf(certificateResolver())->handle(new Request(['disk' => 'private', 'path' => 'salaries.pdf'])));

    expect($result)->toMatchArray(['signed' => false, 'status' => 'refused'])
        ->and($result['message'])->toContain('not open to agents');

    Storage::disk('private')->assertMissing('salaries_signed.pdf');
});

it('refuses to write to a disk the application did not open', function () {
    Storage::fake('private');

    $result = answer(new SignPdf(certificateResolver())->handle(new Request([
        'disk' => 'contracts',
        'path' => 'deal.pdf',
        'destination_disk' => 'private',
    ])));

    expect($result['status'])->toBe('refused');

    Storage::disk('private')->assertMissing('deal_signed.pdf');
});

it('opens no certificate for a call it is going to refuse', function () {
    // The disk and the destination are checked first, so a bad path costs a
    // lookup rather than a decryption. A resolver that was reached would
    // throw, and the call would fail instead of being refused.
    $result = answer(new SignPdf(failingResolver('the certificate was opened'))
        ->handle(new Request(['disk' => 'contracts', 'path' => '../deal.pdf'])));

    expect($result['status'])->toBe('refused');
});

it('returns malformed arguments to the model to correct', function (array $arguments, string $field) {
    expect(fn() => new SignPdf(certificateResolver())->handle(new Request($arguments)))
        ->toThrow(ValidationException::class, $field);
})->with([
    'no disk' => [['path' => 'deal.pdf'], 'disk'],
    'no path' => [['disk' => 'contracts'], 'path'],
    'unknown profile' => [['disk' => 'contracts', 'path' => 'deal.pdf', 'profile' => 'pades-b-xx'], 'profile'],
    'reason too long' => [['disk' => 'contracts', 'path' => 'deal.pdf', 'reason' => str_repeat('x', 256)], 'reason'],
]);

/*
|--------------------------------------------------------------------------
| A repeated call signs once
|--------------------------------------------------------------------------
*/

it('signs once when the same call arrives twice, and answers both alike', function () {
    $tool = new SignPdf(certificateResolver());

    $first = $tool->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf'], 'call_1'));
    $second = $tool->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf'], 'call_1'));

    expect($second)->toBe($first);

    Event::assertDispatchedTimes(DocumentSignedByAgent::class, 1);
});

it('answers a repetition that arrives while the first is still signing', function () {
    app(ToolCallLedger::class)->claim('call_1');

    $result = answer(new SignPdf(certificateResolver())->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf'], 'call_1')));

    expect($result)->toMatchArray(['signed' => false, 'status' => 'in_progress']);

    Storage::disk('contracts')->assertMissing('deal_signed.pdf');
});

it('frees the call when it was refused, so a corrected retry signs', function () {
    Storage::disk('contracts')->put('deal_signed.pdf', 'in the way');
    $tool = new SignPdf(certificateResolver());

    expect(answer($tool->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf'], 'call_1')))['status'])
        ->toBe('refused');

    Storage::disk('contracts')->delete('deal_signed.pdf');

    expect(answer($tool->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf'], 'call_1')))['status'])
        ->toBe('signed');
});

it('frees the call when signing threw, so a retry is not blocked for a day', function () {
    expect(fn() => new SignPdf(failingResolver('the vault is down'))->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf'], 'call_1')))
        ->toThrow(RuntimeException::class, 'the vault is down')
        ->and(app(ToolCallLedger::class)->claim('call_1'))->toBeTrue();
});

it('keeps the call recorded when a listener fails after the copy was written', function () {
    // The copy is on the disk before anybody is told. A listener that throws
    // must not give the id back, or the retry the failure provokes would be
    // answered as a fresh call.
    //
    // A real dispatcher rather than the fake beforeEach installs, since a
    // faked event never reaches its listeners.
    $events = new Illuminate\Events\Dispatcher(app());
    $events->listen(DocumentSignedByAgent::class, function (): never {
        throw new RuntimeException('the audit table is locked');
    });
    app()->instance('events', $events);

    $tool = new SignPdf(certificateResolver());

    expect(fn() => $tool->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf'], 'call_1')))
        ->toThrow(RuntimeException::class, 'the audit table is locked');

    Storage::disk('contracts')->assertExists('deal_signed.pdf');

    expect(answer($tool->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf'], 'call_1'))))
        ->toMatchArray(['signed' => true, 'status' => 'signed', 'path' => 'deal_signed.pdf']);
});

it('signs a second, distinct call rather than treating it as a repetition', function () {
    $tool = new SignPdf(certificateResolver());

    $tool->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf'], 'call_1'));
    $tool->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf', 'destination_path' => 'deal_again.pdf'], 'call_2'));

    Event::assertDispatchedTimes(DocumentSignedByAgent::class, 2);
    Storage::disk('contracts')->assertExists(['deal_signed.pdf', 'deal_again.pdf']);
});

/*
|--------------------------------------------------------------------------
| The certificate comes from the application
|--------------------------------------------------------------------------
*/

it('takes the certificate the application bound', function () {
    app()->instance(SigningCertificateResolver::class, certificateResolver());

    expect(answer(new SignPdf()->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf'])))['signed'])
        ->toBeTrue();
});

it('says what to bind when nothing was', function () {
    expect(fn() => new SignPdf()->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf'], 'call_1')))
        ->toThrow(SigningCertificateUnavailable::class, 'Bind LSNepomuceno\LaravelA1PdfSign\Contracts\SigningCertificateResolver')
        ->and(app(ToolCallLedger::class)->claim('call_1'))->toBeTrue();
});

it('takes no certificate and no password from the model', function () {
    $properties = array_keys(new SignPdf()->schema(new Illuminate\JsonSchema\JsonSchemaTypeFactory()));

    expect($properties)->toBe(['disk', 'path', 'destination_disk', 'destination_path', 'profile', 'reason']);
});

/*
|--------------------------------------------------------------------------
| Approval
|--------------------------------------------------------------------------
*/

it('always asks for approval', function (array $arguments) {
    expect(new SignPdf(certificateResolver())->shouldRequestApproval(new Request($arguments)))
        ->toBeInstanceOf(Approval::class);
})->with([
    'a complete call' => [['disk' => 'contracts', 'path' => 'deal.pdf']],
    'an incomplete call' => [[]],
    'a call to a closed disk' => [['disk' => 'private', 'path' => 'salaries.pdf']],
]);

it('shows the person what would be signed, by whom and where it goes', function () {
    $reason = new SignPdf(certificateResolver())->shouldRequestApproval(new Request([
        'disk' => 'contracts',
        'path' => 'deal.pdf',
        'profile' => 'pades-b-t',
        'reason' => 'Contract approval',
    ]))->reason;

    expect($reason)
        ->toContain('Sign [deal.pdf] on the disk [contracts] with the certificate of Test Certificate')
        ->toContain('valid until')
        ->toContain('Profile: pades-b-t.')
        ->toContain('Reason recorded in the signature: "Contract approval".')
        ->toContain('The signed copy is written to [deal_signed.pdf] on the disk [contracts].');
});

it('warns the person when the certificate would not open', function () {
    expect(new SignPdf(failingResolver('the vault is down'))->shouldRequestApproval(new Request(['disk' => 'contracts', 'path' => 'deal.pdf']))->reason)
        ->toContain('a certificate the application could not open, so signing will fail (the vault is down)');
});

it('describes what is missing rather than inventing it', function () {
    expect(new SignPdf(certificateResolver())->shouldRequestApproval(new Request([]))->reason)
        ->toContain('Sign [(no path given)] on the disk [(no disk given)]')
        ->toContain('Profile: the application\'s configured default.');
});

it('puts the application\'s own words above the description', function () {
    $reason = new SignPdf(certificateResolver())
        ->requireApproval('Requested by the procurement workflow.')
        ->shouldRequestApproval(new Request(['disk' => 'contracts', 'path' => 'deal.pdf']))
        ->reason;

    expect($reason)->toStartWith("Requested by the procurement workflow.\nSign [deal.pdf]");
});

it('cannot be told to stop asking', function () {
    expect(fn() => new SignPdf()->withoutApproval())
        ->toThrow(LogicException::class, 'SignPdf cannot run without approval');
});

it('keeps asking after something tried', function () {
    $tool = new SignPdf(certificateResolver());

    try {
        $tool->withoutApproval();
    } catch (LogicException) {
    }

    expect($tool->shouldRequestApproval(new Request(['disk' => 'contracts', 'path' => 'deal.pdf'])))
        ->toBeInstanceOf(Approval::class);
});

/*
|--------------------------------------------------------------------------
| The application's gate
|--------------------------------------------------------------------------
*/

it('asks the gate with both ends of the signature, and refuses what it refuses', function () {
    $asked = [];

    Gate::define(Ability::Sign->value, function (GenericUser $user, string ...$arguments) use (&$asked) {
        $asked[] = $arguments;

        return false;
    });

    Auth::setUser(new GenericUser(['id' => 42]));

    $result = answer(new SignPdf(certificateResolver())->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf'], 'call_1')));

    expect($asked)->toBe([['contracts', 'deal.pdf', 'contracts', 'deal_signed.pdf']])
        ->and($result)->toMatchArray(['signed' => false, 'status' => 'refused'])
        ->and($result['message'])->toStartWith('You may not sign this document')
        // Refused before anything else: a refused call gives its id back.
        ->and(app(ToolCallLedger::class)->claim('call_1'))->toBeTrue();

    Storage::disk('contracts')->assertMissing('deal_signed.pdf');
    Event::assertNotDispatched(DocumentSignedByAgent::class);
});

it('signs what the gate allows', function () {
    Gate::define(Ability::Sign->value, fn(GenericUser $user) => $user->getAuthIdentifier() === 42);
    Auth::setUser(new GenericUser(['id' => 42]));

    expect(answer(new SignPdf(certificateResolver())->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf'])))['signed'])
        ->toBeTrue();
});

it('opens no certificate for a call the gate refuses', function () {
    Gate::define(Ability::Sign->value, fn(?GenericUser $user) => false);

    expect(answer(new SignPdf(failingResolver('the certificate was opened'))
        ->handle(new Request(['disk' => 'contracts', 'path' => 'deal.pdf'])))['status'])->toBe('refused');
});

it('tells the person approving that the call will be refused', function () {
    // Approval is still asked for: the tool never answers "no approval
    // needed". The person is told what will happen if they say yes.
    Gate::define(Ability::Sign->value, fn(?GenericUser $user) => false);

    expect(new SignPdf(certificateResolver())->shouldRequestApproval(new Request(['disk' => 'contracts', 'path' => 'deal.pdf']))->reason)
        ->toContain('Your application does not allow you to sign this document, so the call will be refused.');
});
