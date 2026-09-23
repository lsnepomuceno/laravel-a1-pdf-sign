<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use LSNepomuceno\LaravelA1PdfSign\Mcp\A1PdfSignServer;
use LSNepomuceno\LaravelA1PdfSign\Mcp\Tools\ValidatePdfSignature;

/**
 * Validation, asked by an MCP client.
 *
 * Every case goes through the server, the way a client reaches the tool, so
 * the argument handling, the error mapping and the structured content are all
 * the ones a client actually sees.
 */
beforeEach(function () {
    Storage::fake('contracts');
});

it('verifies a signed document and says who signed it', function () {
    $signer = signedOnDisk('contracts', 'deal_signed.pdf');

    A1PdfSignServer::tool(ValidatePdfSignature::class, ['disk' => 'contracts', 'path' => 'deal_signed.pdf'])
        ->assertOk()
        ->assertStructuredContent(fn(AssertableJson $json) => $json
            ->where('disk', 'contracts')
            ->where('path', 'deal_signed.pdf')
            ->where('signed', true)
            ->where('valid', true)
            ->where('signature_count', 1)
            ->where('certified', false)
            ->where('certification_level', null)
            ->where('accepts_further_signatures', true)
            ->where('trusted', null)
            ->where('document_findings', [])
            ->has('signatures', 1, fn(AssertableJson $signature) => $signature
                ->where('signer', $signer)
                ->where('registry', null)
                ->where('verified', true)
                ->where('is_timestamp', false)
                ->where('covers_whole_document', true)
                ->where('profile', 'pades-b-b')
                ->where('signed_at', fn(?string $time) => $time !== null && strtotime($time) !== false)
                ->where('attested_at', null)
                ->where('revocation', 'unknown')
                ->where('findings', fn(Illuminate\Support\Collection $findings) => $findings->contains('revocation-unknown'))
                ->etc()));
});

it('passes on the engine\'s own words for a document with no signature', function () {
    // The engine raises for an unsigned document rather than returning an
    // empty report, and it cannot tell "no signature" from "a signature it
    // cannot parse". Answering `signed: false` would be claiming more than it
    // knows, so the tool relays the doubt as an error the model can repeat.
    Storage::disk('contracts')->put('blank.pdf', (string) file_get_contents(resource('test.pdf')));
    config()->set('a1-pdf-sign.agents.disks', ['contracts']);

    A1PdfSignServer::tool(ValidatePdfSignature::class, ['disk' => 'contracts', 'path' => 'blank.pdf'])
        ->assertHasErrors(['The document was not validated', 'unsigned']);
});

it('refuses a disk the application did not open, and says which are', function () {
    Storage::fake('private');
    signedOnDisk('private', 'salaries.pdf');
    config()->set('a1-pdf-sign.agents.disks', ['contracts']);

    A1PdfSignServer::tool(ValidatePdfSignature::class, ['disk' => 'private', 'path' => 'salaries.pdf'])
        ->assertHasErrors(['The disk [private] is not open to agents', 'The disks open to agents are: contracts.']);
});

it('refuses a path that climbs out of the disk', function () {
    config()->set('a1-pdf-sign.agents.disks', ['contracts']);

    A1PdfSignServer::tool(ValidatePdfSignature::class, ['disk' => 'contracts', 'path' => '../../.env.pdf'])
        ->assertHasErrors(['climbs out']);
});

it('says the document is missing rather than failing somewhere inside the engine', function () {
    config()->set('a1-pdf-sign.agents.disks', ['contracts']);

    A1PdfSignServer::tool(ValidatePdfSignature::class, ['disk' => 'contracts', 'path' => 'nothing.pdf'])
        ->assertHasErrors(['There is no document at [nothing.pdf]']);
});

it('returns malformed arguments to the client to correct', function () {
    config()->set('a1-pdf-sign.agents.disks', ['contracts']);

    A1PdfSignServer::tool(ValidatePdfSignature::class, ['disk' => 'contracts'])
        ->assertHasErrors(['path']);
});

it('reports what the engine could not read, as an error the client can show', function () {
    Storage::disk('contracts')->put('broken.pdf', 'this is not a PDF at all');
    config()->set('a1-pdf-sign.agents.disks', ['contracts']);

    A1PdfSignServer::tool(ValidatePdfSignature::class, ['disk' => 'contracts', 'path' => 'broken.pdf'])
        ->assertHasErrors(['The document was not validated']);
});

it('hands over the registry only when the application allows it', function () {
    // The debug certificate carries no ICP-Brasil identity, so the registry is
    // null either way. What is asserted is that the switch reaches the
    // summary: a certificate with a CPF would have it here.
    signedOnDisk('contracts', 'deal_signed.pdf');
    config()->set('a1-pdf-sign.agents.expose_registry', true);

    A1PdfSignServer::tool(ValidatePdfSignature::class, ['disk' => 'contracts', 'path' => 'deal_signed.pdf'])
        ->assertOk()
        ->assertStructuredContent(fn(AssertableJson $json) => $json
            ->has('signatures.0.registry')
            ->etc());
});

it('writes nothing to the disk it reads', function () {
    signedOnDisk('contracts', 'deal_signed.pdf');
    $before = Storage::disk('contracts')->allFiles();

    A1PdfSignServer::tool(ValidatePdfSignature::class, ['disk' => 'contracts', 'path' => 'deal_signed.pdf'])->assertOk();

    expect(Storage::disk('contracts')->allFiles())->toBe($before);
});
