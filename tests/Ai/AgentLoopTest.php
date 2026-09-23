<?php

declare(strict_types=1);

use Illuminate\Support\Facades\{Event, Storage};
use Laravel\Ai\Responses\Data\{ToolCall, ToolResult};
use Laravel\Ai\Tools\{McpServerTool, Request};
use LSNepomuceno\LaravelA1PdfSign\Ai\Tools\SignPdf;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SigningCertificateResolver;
use LSNepomuceno\LaravelA1PdfSign\Events\DocumentSignedByAgent;
use LSNepomuceno\LaravelA1PdfSign\Mcp\Tools\ValidatePdfSignature;
use LSNepomuceno\LaravelA1PdfSign\Tests\Fixtures\DocumentAgent;
use LSNepomuceno\Signet\Data\Certificate;

/**
 * The tools inside a real AI SDK run, with the model faked.
 *
 * `DocumentAgent::fake()` replaces the provider, not the loop: the fake answers
 * with a tool call, and the SDK's own loop decides whether to run it, wraps
 * the MCP tools, asks for approval and pauses. That loop is what an
 * application relies on, so it is what these tests drive.
 */
beforeEach(function () {
    Storage::fake('contracts');
    Event::fake([DocumentSignedByAgent::class]);

    app()->instance(SigningCertificateResolver::class, new readonly class implements SigningCertificateResolver {
        public function resolve(): Certificate
        {
            return testCertificate();
        }
    });
});

it('pauses for a person before signing anything', function () {
    Storage::disk('contracts')->put('deal.pdf', (string) file_get_contents(resource('test.pdf')));
    config()->set('a1-pdf-sign.agents.disks', ['contracts']);

    DocumentAgent::fake([
        new ToolCall('call_1', 'sign_pdf', ['disk' => 'contracts', 'path' => 'deal.pdf']),
    ]);

    $response = new DocumentAgent()->prompt('Sign the deal.');

    expect($response->hasPendingApprovals())->toBeTrue()
        ->and($response->pendingApprovals)->toHaveCount(1);

    $approval = $response->pendingApprovals->first();

    expect($approval->id)->toBe('call_1')
        ->and($approval->tool)->toBe('sign_pdf')
        ->and($approval->arguments)->toBe(['disk' => 'contracts', 'path' => 'deal.pdf'])
        ->and($approval->reason)->toContain('Sign [deal.pdf] on the disk [contracts] with the certificate of Test Certificate');

    // Nothing was signed, nothing was written and nothing was announced: the
    // run stopped at the question.
    Storage::disk('contracts')->assertMissing('deal_signed.pdf');
    Event::assertNotDispatched(DocumentSignedByAgent::class);
});

it('pauses whatever the application adds to the question', function () {
    // requireApproval() only changes the words. There is no path from the
    // application's side to a run that signs without asking.
    Storage::disk('contracts')->put('deal.pdf', (string) file_get_contents(resource('test.pdf')));
    config()->set('a1-pdf-sign.agents.disks', ['contracts']);

    DocumentAgent::fake([
        new ToolCall('call_1', 'sign_pdf', ['disk' => 'contracts', 'path' => 'deal.pdf']),
    ]);

    $response = new DocumentAgent(new SignPdf()->requireApproval('Nightly batch.'))->prompt('Sign the deal.');

    expect($response->pendingApprovals->first()->reason)->toStartWith('Nightly batch.');

    Storage::disk('contracts')->assertMissing('deal_signed.pdf');
});

it('runs the MCP validation tool inside an AI SDK agent, with no approval', function () {
    signedOnDisk('contracts', 'deal_signed.pdf');

    DocumentAgent::fake([
        new ToolCall('call_1', 'validate_pdf_signature', ['disk' => 'contracts', 'path' => 'deal_signed.pdf']),
        'The contract carries one valid signature, by Test Certificate.',
    ]);

    $response = new DocumentAgent()->prompt('Is the deal signed?');

    expect($response->hasPendingApprovals())->toBeFalse()
        ->and($response->text)->toBe('The contract carries one valid signature, by Test Certificate.');

    /** @var ToolResult $result */
    $result = $response->toolResults->first();
    $report = json_decode(is_string($result->result) ? $result->result : '', true, flags: JSON_THROW_ON_ERROR);

    expect($result->name)->toBe('validate_pdf_signature')
        ->and($report)->toMatchArray(['signed' => true, 'valid' => true, 'signature_count' => 1])
        ->and(data_get($report, 'signatures.0.signer'))->toBe('Test Certificate');
});

it('hands the model a refusal it can read when the disk is closed', function () {
    signedOnDisk('contracts', 'deal_signed.pdf');
    config()->set('a1-pdf-sign.agents.disks', []);

    DocumentAgent::fake([
        new ToolCall('call_1', 'list_signature_fields', ['disk' => 'contracts', 'path' => 'deal_signed.pdf']),
        'I cannot reach that document.',
    ]);

    $response = new DocumentAgent()->prompt('Which fields are empty?');

    expect($response->toolResults->first()?->result)
        ->toStartWith('MCP tool error:')
        ->toContain('No disk is open to agents');
});

it('is wrapped by the SDK on its own when an agent returns it bare', function () {
    // The guide recommends wrapping by hand, for static analysis. This is the
    // other half: an application that returns the tool bare still works,
    // because the SDK recognises it and applies the same wrapper.
    expect(McpServerTool::supports(new ValidatePdfSignature()))->toBeTrue()
        ->and(McpServerTool::supports(new LSNepomuceno\LaravelA1PdfSign\Mcp\Tools\ListSignatureFields()))->toBeTrue();
});

it('wraps the MCP tools the way the SDK does, as JSON', function () {
    signedOnDisk('contracts', 'deal_signed.pdf');

    $json = new McpServerTool(new ValidatePdfSignature())->handle(new Request(['disk' => 'contracts', 'path' => 'deal_signed.pdf']));

    expect(json_decode($json, true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray(['disk' => 'contracts', 'path' => 'deal_signed.pdf', 'valid' => true]);
});
