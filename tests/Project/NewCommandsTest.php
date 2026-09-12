<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

beforeEach(function () {
    $this->withoutMockingConsoleOutput();
});

it('lists the fields a template declares', function () {
    expect(Artisan::call('pdf:fields', ['pdfPath' => resource('signature-fields.pdf')]))->toBe(0);

    expect(Artisan::output())->toContain('Name')
        ->toContain('Signed');
});

it('says so plainly when a document declares no fields', function () {
    expect(Artisan::call('pdf:fields', ['pdfPath' => resource('test.pdf')]))->toBe(0)
        ->and(Artisan::output())->toContain('no signature fields');
});

it('exits non-zero when the document cannot be read', function () {
    expect(Artisan::call('pdf:fields', ['pdfPath' => '/nowhere/at/all.pdf']))->toBe(1)
        ->and(Artisan::output())->toContain('Could not read the document');
});

it('adds an empty field a later signature can be addressed to', function () {
    $output = A1PdfSign::tempPath(true, '.pdf');

    expect(Artisan::call('pdf:add-field', [
        'pdfPath' => resource('test.pdf'),
        'name' => 'Manager',
        'output' => $output,
    ]))->toBe(0);

    // The field is there, unsigned, and addressable by name, which is what
    // makes the template half of the package usable without a designer.
    $fields = A1PdfSign::signatureFields($output);

    expect($fields)->toHaveCount(1)
        ->and($fields[0]->name)->toBe('Manager')
        ->and($fields[0]->isSigned)->toBeFalse();

    unlink($output);
});

it('places the field where it is asked to', function () {
    $output = A1PdfSign::tempPath(true, '.pdf');

    Artisan::call('pdf:add-field', [
        'pdfPath' => resource('test.pdf'),
        'name' => 'Visible',
        'output' => $output,
        '--x' => 60,
        '--y' => 400,
        '--width' => 120,
        '--height' => 40,
    ]);

    $field = A1PdfSign::signatureFields($output)[0];

    expect($field->isVisible())->toBeTrue()
        ->and($field->rectangle[0])->toBe(60.0);

    unlink($output);
});

it('refuses half a placement rather than guessing the other half', function () {
    // A rectangle with a width and no height is not a smaller rectangle, it is
    // an unanswerable question, and the engine says so by name.
    expect(Artisan::call('pdf:add-field', [
        'pdfPath' => resource('test.pdf'),
        'name' => 'Half',
        '--x' => 60,
        '--y' => 400,
        '--width' => 120,
    ]))->toBe(1)
        ->and(Artisan::output())->toContain('no width or no height');
});

it('reports rather than raises when there is nothing to extend', function () {
    expect(Artisan::call('pdf:extend', ['pdfPath' => resource('test.pdf')]))->toBe(1)
        ->and(Artisan::output())->toContain('Could not extend the archive');
});
