<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Mcp\A1PdfSignServer;
use LSNepomuceno\LaravelA1PdfSign\Mcp\Tools\ListSignatureFields;

/**
 * Field listing, asked by an MCP client.
 */
beforeEach(function () {
    Storage::fake('contracts');
    config()->set('a1-pdf-sign.agents.disks', ['contracts']);
});

it('lists the fields a template declares, and how many are still empty', function () {
    Storage::disk('contracts')->put('template.pdf', (string) file_get_contents(resource('signature-fields.pdf')));

    $expected = A1PdfSign::signatureFields(resource('signature-fields.pdf'));

    A1PdfSignServer::tool(ListSignatureFields::class, ['disk' => 'contracts', 'path' => 'template.pdf'])
        ->assertOk()
        ->assertStructuredContent(fn(AssertableJson $json) => $json
            ->where('disk', 'contracts')
            ->where('path', 'template.pdf')
            ->where('field_count', count($expected))
            ->where('unsigned_count', count(array_filter($expected, fn($field) => ! $field->isSigned)))
            ->has('fields', count($expected), fn(AssertableJson $field) => $field
                ->where('name', $expected[0]->name)
                ->where('signed', $expected[0]->isSigned)
                ->where('page', $expected[0]->pageNumber)
                ->where('visible', $expected[0]->isVisible())));
});

it('counts a field as signed once somebody signs into it', function () {
    [$pfxPath, $password] = debugCertificate();
    $template = A1PdfSign::signatureFields(resource('signature-fields.pdf'));

    Storage::disk('contracts')->put(
        'template_signed.pdf',
        A1PdfSign::newSignature()
            ->certificate($pfxPath, $password)
            ->pdf(resource('signature-fields.pdf'))
            ->intoField($template[0]->name)
            ->sign()
            ->contents,
    );

    A1PdfSignServer::tool(ListSignatureFields::class, ['disk' => 'contracts', 'path' => 'template_signed.pdf'])
        ->assertOk()
        ->assertStructuredContent(fn(AssertableJson $json) => $json
            ->where('field_count', count($template))
            ->where('unsigned_count', count($template) - 1)
            ->where('fields.0.signed', true)
            ->etc());
});

it('answers an empty list for a document with no fields', function () {
    Storage::disk('contracts')->put('plain.pdf', (string) file_get_contents(resource('test.pdf')));

    A1PdfSignServer::tool(ListSignatureFields::class, ['disk' => 'contracts', 'path' => 'plain.pdf'])
        ->assertOk()
        ->assertStructuredContent([
            'disk' => 'contracts',
            'path' => 'plain.pdf',
            'field_count' => 0,
            'unsigned_count' => 0,
            'fields' => [],
        ]);
});

it('refuses what DocumentAccess refuses', function (?string $disk, string $path, string $error) {
    $arguments = array_filter(['disk' => $disk, 'path' => $path], static fn(?string $value): bool => $value !== null);

    A1PdfSignServer::tool(ListSignatureFields::class, $arguments)->assertHasErrors([$error]);
})->with([
    'closed disk' => ['local', 'x.pdf', 'not open to agents'],
    'absolute path' => ['contracts', '/etc/passwd.pdf', 'absolute'],
    'not a pdf' => ['contracts', 'notes.txt', 'only PDF documents'],
    'missing' => ['contracts', 'nope.pdf', 'There is no document'],
    'no disk' => [null, 'x.pdf', 'disk'],
]);
