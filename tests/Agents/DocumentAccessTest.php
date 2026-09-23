<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\{Auth, Gate, Storage};
use LSNepomuceno\LaravelA1PdfSign\Agents\Ability;
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

/*
|--------------------------------------------------------------------------
| The application's gate
|--------------------------------------------------------------------------
*/

it('allows everything on an open disk when the application defines no ability', function () {
    // What 3.1.0 shipped, and what an application upgrading keeps until it
    // defines one: the disk list is the control.
    app(DocumentAccess::class)->authorize(Ability::Read, 'contracts', 'deal.pdf');

    expect(app(DocumentAccess::class)->allows(Ability::Sign, 'contracts', 'deal.pdf', 'contracts', 'deal_signed.pdf'))
        ->toBeTrue();
});

it('asks the gate with the user, the disk and the path', function () {
    $asked = [];

    Gate::define(Ability::Read->value, function (GenericUser $user, string $disk, string $path) use (&$asked) {
        $asked[] = [$user->getAuthIdentifier(), $disk, $path];

        return $path === 'deal.pdf';
    });

    Auth::setUser(new GenericUser(['id' => 7]));

    app(DocumentAccess::class)->authorize(Ability::Read, 'contracts', 'deal.pdf');

    expect($asked)->toBe([[7, 'contracts', 'deal.pdf']])
        ->and(fn() => app(DocumentAccess::class)->authorize(Ability::Read, 'contracts', 'other.pdf'))
        ->toThrow(AuthorizationException::class);
});

it('hands the signing ability the destination as well', function () {
    Gate::define(Ability::Sign->value, fn(GenericUser $user, string $disk, string $path, string $destinationDisk, string $destinationPath) => $destinationDisk === 'contracts');

    Auth::setUser(new GenericUser(['id' => 7]));

    expect(app(DocumentAccess::class)->allows(Ability::Sign, 'contracts', 'deal.pdf', 'contracts', 'deal_signed.pdf'))->toBeTrue()
        ->and(app(DocumentAccess::class)->allows(Ability::Sign, 'contracts', 'deal.pdf', 'archive', 'deal.pdf'))->toBeFalse();
});

it('refuses a guest once the application defines an ability', function () {
    // Laravel's gate refuses a guest unless the ability's user is nullable,
    // and an agent served over stdio has no user at all.
    Gate::define(Ability::Read->value, fn(GenericUser $user) => true);

    expect(fn() => app(DocumentAccess::class)->authorize(Ability::Read, 'contracts', 'deal.pdf'))
        ->toThrow(AuthorizationException::class);
});

it('names the abilities the way the guide does', function () {
    expect(Ability::Read->value)->toBe('a1-pdf-sign.agents.read')
        ->and(Ability::Sign->value)->toBe('a1-pdf-sign.agents.sign');
});

/*
|--------------------------------------------------------------------------
| Size
|--------------------------------------------------------------------------
*/

it('refuses a document larger than the configured limit', function () {
    config()->set('a1-pdf-sign.agents.max_bytes', 10);

    expect(fn() => app(DocumentAccess::class)->source('contracts', 'deal.pdf'))
        ->toThrow(DocumentOutOfReach::class, 'is larger than the 10 bytes agents may work with');

    config()->set('a1-pdf-sign.agents.max_bytes', 52_428_800);
    Storage::disk('contracts')->put('huge.pdf', str_repeat('x', 52_428_801));

    expect(fn() => app(DocumentAccess::class)->source('contracts', 'huge.pdf'))
        ->toThrow(DocumentOutOfReach::class, 'is larger than the 50 MB agents may work with');
});

it('ships with a limit of 50 MB', function () {
    $shipped = require packageRoot() . '/config/a1-pdf-sign.php';

    expect(data_get($shipped, 'agents.max_bytes'))->toBe(DocumentAccess::DEFAULT_MAX_BYTES)
        ->and(DocumentAccess::DEFAULT_MAX_BYTES)->toBe(52_428_800);
});

it('keeps the limit for a config published before the key existed', function () {
    // The merge is shallow: a 3.1.0 config's `agents` block, which has no
    // max_bytes, replaces the package's whole. Absent has to mean the
    // default, or publishing the config would have switched the limit off.
    config()->set('a1-pdf-sign.agents', [
        'disks' => ['contracts'],
        'expose_registry' => false,
        'idempotency' => ['store' => null, 'ttl' => 86400],
    ]);

    expect(app(DocumentAccess::class)->maxBytes())->toBe(DocumentAccess::DEFAULT_MAX_BYTES);
});

it('removes the limit when the application sets none', function (mixed $limit) {
    config()->set('a1-pdf-sign.agents.max_bytes', $limit);

    expect(app(DocumentAccess::class)->maxBytes())->toBeNull()
        ->and(app(DocumentAccess::class)->source('contracts', 'deal.pdf'))->toBeInstanceOf(DiskSource::class);
})->with([[null], [0], ['none']]);
