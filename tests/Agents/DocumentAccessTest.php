<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use LSNepomuceno\LaravelA1PdfSign\Agents\DocumentAccess;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\DocumentOutOfReach;
use LSNepomuceno\LaravelA1PdfSign\Io\{DiskDestination, DiskSource};
use LSNepomuceno\Signet\Exceptions\SignetException;

/**
 * The only way an agent reaches a document.
 *
 * Every rule here is a refusal, because the argument it guards comes from a
 * model and whoever writes the prompt steers the model. Needs neither SDK, so
 * it also runs in the CI job that removes them.
 */
beforeEach(function () {
    Storage::fake('contracts');
    Storage::fake('private');
    Storage::disk('contracts')->put('deal.pdf', '%PDF-1.4 a contract %%EOF');
    Storage::disk('private')->put('salaries.pdf', '%PDF-1.4 not for agents %%EOF');

    config()->set('a1-pdf-sign.agents.disks', ['contracts']);
});

it('opens nothing on a fresh install', function () {
    // The config file ships with an empty list, so an application that
    // installs an SDK and adds a tool without reading further reaches no disk.
    $shipped = require packageRoot() . '/config/a1-pdf-sign.php';
    config()->set('a1-pdf-sign.agents.disks', data_get($shipped, 'agents.disks'));

    expect(app(DocumentAccess::class)->disks())->toBe([])
        ->and(fn() => app(DocumentAccess::class)->source('contracts', 'deal.pdf'))
        ->toThrow(DocumentOutOfReach::class, 'No disk is open to agents');
});

it('reaches a document on a disk the application opened', function () {
    $source = app(DocumentAccess::class)->source('contracts', 'deal.pdf');

    expect($source)->toBeInstanceOf(DiskSource::class)
        ->and($source->contents())->toContain('a contract')
        ->and($source->name())->toBe('deal.pdf');
});

it('refuses a disk the application did not open, and names the ones it did', function () {
    expect(fn() => app(DocumentAccess::class)->source('private', 'salaries.pdf'))
        ->toThrow(DocumentOutOfReach::class, 'The disk [private] is not open to agents. The disks open to agents are: contracts.');
});

it('refuses a path an agent should not be able to name', function (string $path, string $why) {
    expect(fn() => app(DocumentAccess::class)->source('contracts', $path))
        ->toThrow(DocumentOutOfReach::class, $why);
})->with([
    'empty' => ['', 'it is empty'],
    'blank' => ['   ', 'it is empty'],
    'null byte' => ["deal.pdf\0.txt", 'null byte'],
    'backslash' => ['contracts\\deal.pdf', 'backslash'],
    'absolute' => ['/etc/deal.pdf', 'absolute'],
    'drive letter' => ['C:/deal.pdf', 'absolute'],
    'climbing' => ['../private/salaries.pdf', 'climbs out'],
    'climbing midway' => ['2026/../../salaries.pdf', 'climbs out'],
    'not a pdf' => ['.env', 'only PDF documents'],
    'pdf in the middle' => ['deal.pdf.php', 'only PDF documents'],
]);

it('accepts the extension in any case', function () {
    Storage::disk('contracts')->put('LOUD.PDF', '%PDF-1.4 %%EOF');

    expect(app(DocumentAccess::class)->source('contracts', 'LOUD.PDF'))->toBeInstanceOf(DiskSource::class);
});

it('says there is no document rather than handing the engine nothing', function () {
    expect(fn() => app(DocumentAccess::class)->source('contracts', 'missing.pdf'))
        ->toThrow(DocumentOutOfReach::class, 'There is no document at [missing.pdf] on the disk [contracts].');
});

it('writes to a free destination', function () {
    $destination = app(DocumentAccess::class)->destination('contracts', 'deal_signed.pdf');

    expect($destination)->toBeInstanceOf(DiskDestination::class)
        ->and($destination->write('%PDF-1.4 signed %%EOF', 'ignored.pdf'))->toBe('deal_signed.pdf');

    Storage::disk('contracts')->assertExists('deal_signed.pdf');
});

it('never offers a destination that already exists', function () {
    expect(fn() => app(DocumentAccess::class)->destination('contracts', 'deal.pdf'))
        ->toThrow(DocumentOutOfReach::class, 'a signed document never overwrites one');

    expect(Storage::disk('contracts')->get('deal.pdf'))->toBe('%PDF-1.4 a contract %%EOF');
});

it('guards a destination as strictly as a source', function () {
    expect(fn() => app(DocumentAccess::class)->destination('private', 'copy.pdf'))
        ->toThrow(DocumentOutOfReach::class, 'not open to agents')
        ->and(fn() => app(DocumentAccess::class)->destination('contracts', '../copy.pdf'))
        ->toThrow(DocumentOutOfReach::class, 'climbs out');
});

it('names a signed copy the way the engine does', function (string $original, string $signed) {
    expect(DocumentAccess::signedName($original))->toBe($signed);
})->with([
    ['deal.pdf', 'deal_signed.pdf'],
    ['contracts/2026/deal.pdf', 'contracts/2026/deal_signed.pdf'],
    ['contracts/DEAL.PDF', 'contracts/DEAL_signed.pdf'],
]);

it('keeps the registry from the model unless the application says otherwise', function () {
    expect(app(DocumentAccess::class)->exposesRegistry())->toBeFalse();

    config()->set('a1-pdf-sign.agents.expose_registry', true);

    expect(app(DocumentAccess::class)->exposesRegistry())->toBeTrue();
});

it('is caught with every other failure of the package', function () {
    expect(DocumentOutOfReach::missing('contracts', 'x.pdf'))->toBeInstanceOf(SignetException::class)
        ->toBeInstanceOf(InvalidArgumentException::class);
});
