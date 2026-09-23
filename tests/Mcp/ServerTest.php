<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Container\Container;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use LSNepomuceno\LaravelA1PdfSign\Mcp\A1PdfSignServer;
use LSNepomuceno\LaravelA1PdfSign\Mcp\Tools\{ListSignatureFields, ValidatePdfSignature};

/**
 * The server an application registers in routes/ai.php, and what it offers.
 */
it('offers the two read-only tools', function () {
    A1PdfSignServer::tools()->assertRegistered([ValidatePdfSignature::class, ListSignatureFields::class]);
});

it('offers nothing that can sign', function () {
    // Signing lives on the AI SDK side, where approval is enforced by the
    // tool itself rather than left to whichever client connects.
    $names = [];

    foreach ((array) new ReflectionProperty(A1PdfSignServer::class, 'tools')->getDefaultValue() as $tool) {
        if (is_string($tool)) {
            $names[] = data_get(Container::getInstance()->make($tool)->toArray(), 'name');
        }
    }

    expect($names)->toBe(['validate_pdf_signature', 'list_signature_fields'])
        ->and(in_array('sign_pdf', $names, true))->toBeFalse();
});

it('marks every tool as read-only, idempotent and closed-world', function (string $tool) {
    $annotations = (array) data_get(Container::getInstance()->make($tool)->toArray(), 'annotations');

    expect($annotations)->toMatchArray([
        'readOnlyHint' => true,
        'idempotentHint' => true,
        'openWorldHint' => false,
    ]);
})->with([ValidatePdfSignature::class, ListSignatureFields::class]);

it('names its tools in the snake case the signing tool uses', function () {
    expect(new ValidatePdfSignature()->name())->toBe('validate_pdf_signature')
        ->and(new ListSignatureFields()->name())->toBe('list_signature_fields');
});

it('offers the model only the disks the application opened', function (string $tool) {
    config()->set('a1-pdf-sign.agents.disks', ['contracts', 'archive']);

    $schema = data_get(Container::getInstance()->make($tool)->toArray(), 'inputSchema');

    expect(data_get($schema, 'properties.disk.enum'))->toBe(['contracts', 'archive'])
        ->and(data_get($schema, 'required'))->toBe(['disk', 'path']);
})->with([ValidatePdfSignature::class, ListSignatureFields::class]);

it('tells the model a path does not start with the disk\'s name', function (string $tool) {
    // Found against a real provider: shown `contracts/2026/deal.pdf` as the
    // example, DeepSeek looked for `deal.pdf` on the `contracts` disk at
    // `contracts/deal.pdf` and four other paths, each with the disk's name in
    // front. The example a schema gives is the example a model copies.
    $description = data_get(Container::getInstance()->make($tool)->toArray(), 'inputSchema.properties.path.description');

    expect($description)->toBe(LSNepomuceno\LaravelA1PdfSign\Agents\Arguments::PATH)
        ->and(LSNepomuceno\LaravelA1PdfSign\Agents\Arguments::PATH)
        ->toContain('Do not repeat the disk\'s name', 'such as deal.pdf')
        ->and(str_contains(LSNepomuceno\LaravelA1PdfSign\Agents\Arguments::PATH, 'contracts/'))->toBeFalse();
})->with([ValidatePdfSignature::class, ListSignatureFields::class]);

it('leaves the enum out rather than offering an empty one', function () {
    // An enum with no values is a schema nothing satisfies, and some
    // providers reject the whole tool list over it.
    config()->set('a1-pdf-sign.agents.disks', []);

    $disk = data_get(new ValidatePdfSignature()->toArray(), 'inputSchema.properties.disk');

    expect(data_get($disk, 'type'))->toBe('string')
        ->and(data_get($disk, 'enum'))->toBeNull();
});

it('declares the shape of what it returns', function (string $tool, array $keys) {
    $properties = (array) data_get(Container::getInstance()->make($tool)->toArray(), 'outputSchema.properties');

    expect(array_keys($properties))->toBe($keys);
})->with([
    [ValidatePdfSignature::class, [
        'disk', 'path', 'signed', 'valid', 'signature_count', 'certified', 'certification_level',
        'accepts_further_signatures', 'trusted', 'document_findings', 'signatures',
    ]],
    [ListSignatureFields::class, ['disk', 'path', 'field_count', 'unsigned_count', 'fields']],
]);

it('takes an application\'s own tools beside its own, the way the guide shows', function () {
    $server = new class (new FakeTransporter()) extends A1PdfSignServer {
        public function __construct(Laravel\Mcp\Server\Contracts\Transport $transport)
        {
            parent::__construct($transport);

            $this->tools[] = ListSignatureFields::class;
        }
    };

    expect(new ReflectionProperty($server, 'tools')->getValue($server))->toBe([
        ValidatePdfSignature::class,
        ListSignatureFields::class,
        ListSignatureFields::class,
    ]);
});

it('reports the version Composer installed rather than a literal', function () {
    $server = new A1PdfSignServer(new FakeTransporter());

    $version = new ReflectionProperty($server, 'version')->getValue($server);

    expect($version)->toBe(InstalledVersions::getPrettyVersion('lsnepomuceno/laravel-a1-pdf-sign'));
});

/*
|--------------------------------------------------------------------------
| Behind ToolSearch, in an application's own server
|--------------------------------------------------------------------------
*/

it('is found by searching when an application groups it behind ToolSearch', function () {
    LSNepomuceno\LaravelA1PdfSign\Tests\Fixtures\CatalogServer::tool(
        new Laravel\Mcp\Server\Tools\SearchTools(new Laravel\Mcp\Server\Tools\ToolSearch([ValidatePdfSignature::class, ListSignatureFields::class])),
        ['query' => 'signature'],
    )->assertOk()->assertSee(['validate_pdf_signature', 'list_signature_fields', 'readOnlyHint']);
});

it('runs through execute_tools, refusals included', function () {
    Illuminate\Support\Facades\Storage::fake('contracts');
    signedOnDisk('contracts', 'deal_signed.pdf');

    $catalog = new Laravel\Mcp\Server\Tools\ToolSearch([ValidatePdfSignature::class, ListSignatureFields::class]);
    $execute = new Laravel\Mcp\Server\Tools\ExecuteTools($catalog, 25);

    LSNepomuceno\LaravelA1PdfSign\Tests\Fixtures\CatalogServer::tool($execute, ['calls' => [
        ['name' => 'validate_pdf_signature', 'arguments' => ['disk' => 'contracts', 'path' => 'deal_signed.pdf']],
    ]])->assertOk()->assertSee(['"valid":true', 'Test Certificate']);

    LSNepomuceno\LaravelA1PdfSign\Tests\Fixtures\CatalogServer::tool($execute, ['calls' => [
        ['name' => 'list_signature_fields', 'arguments' => ['disk' => 'private', 'path' => 'x.pdf']],
    ]])->assertHasErrors(['not open to agents']);
});

it('shows a client only the two meta-tools, which is why the package server does not group', function () {
    // execute_tools is open-world and not read-only, so a client that could
    // run validate_pdf_signature without asking would ask for every call.
    $tools = [];

    foreach (new LSNepomuceno\LaravelA1PdfSign\Tests\Fixtures\CatalogServer(new FakeTransporter())->createContext()->tools() as $tool) {
        $tools[$tool->name()] = data_get($tool->toArray(), 'annotations');
    }

    expect(array_keys($tools))->toBe(['search_tools', 'execute_tools'])
        ->and((array) $tools['execute_tools'])->toMatchArray(['openWorldHint' => true])
        ->and((array) $tools['execute_tools'])->not->toHaveKey('readOnlyHint');
});
