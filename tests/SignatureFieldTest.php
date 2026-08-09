<?php

use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureField;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\SignatureFieldException;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\SignatureFieldReader;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;

/**
 * Signing into a field the document already carries.
 *
 * The case is a template someone else laid out: a contract with an empty
 * SignatureManager and an empty SignatureEmployee, where the application fills
 * the right one. See docs/decisions/0013-signing-into-an-existing-field.md.
 *
 * template() and pdfWith() live in tests/Pest.php: a helper defined inside one
 * test file is invisible to the others under --parallel.
 */

it('lists the signature fields a template carries', function () {
    $fields = app(SignatureFieldReader::class)->read(template());

    expect($fields)->toHaveCount(2)
        ->and($fields[0]->name)->toBe('SignatureManager')
        ->and($fields[0]->isSigned)->toBeFalse()
        ->and($fields[0]->pageNumber)->toBe(3)
        ->and($fields[0]->rectangle)->toBe([30.0, 200.0, 200.0, 250.0])
        // The order is the form's, not the file's: /Fields is what an
        // application shows a user, so reordering it would reorder the form.
        ->and($fields[1]->name)->toBe('SignatureEmployee');
});

it('exposes the fields through the facade', function () {
    $fields = A1PdfSign::signatureFields(resource('signature-fields.pdf'));

    expect($fields)->toHaveCount(2)
        ->and($fields[0])->toBeInstanceOf(SignatureField::class);
});

it('reports no fields for a document that carries none', function () {
    expect(app(SignatureFieldReader::class)->read(Files::read(resource('test.pdf'))))->toBe([]);
});

it('fills the named field rather than appending one beside it', function () {
    // The failure this exists to prevent: a signature that is valid and in the
    // wrong place, with the template's own field still empty.
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents(template(), 'contract.pdf')
        ->intoField('SignatureEmployee')
        ->sign();

    $fields = app(SignatureFieldReader::class)->read($signed->contents);

    expect($fields)->toHaveCount(2)
        ->and($fields[0]->name)->toBe('SignatureManager')
        ->and($fields[0]->isSigned)->toBeFalse()
        ->and($fields[1]->name)->toBe('SignatureEmployee')
        ->and($fields[1]->isSigned)->toBeTrue()
        // The widget keeps its own object number: the revision replaced that
        // object instead of adding a second one.
        ->and($fields[1]->objectNumber)->toBe(6)
        ->and($fields[1]->rectangle)->toBe([30.0, 100.0, 200.0, 150.0]);

    expect(app(SignatureValidator::class)->validate($signed->contents)->isValid())->toBeTrue();
});

it('fills each field independently, in whatever order they are named', function () {
    // Signing the second field first is what catches a writer that fills "the
    // next empty one" rather than the one it was told to.
    [$pfxPath, $password] = debugCertificate();

    $pdf = template();

    foreach (['SignatureEmployee', 'SignatureManager'] as $name) {
        $pdf = A1PdfSign::newSignature()
            ->certificate($pfxPath, $password)
            ->pdfContents($pdf, 'contract.pdf')
            ->intoField($name)
            ->sign()
            ->contents;
    }

    $fields = app(SignatureFieldReader::class)->read($pdf);

    expect($fields)->toHaveCount(2)
        ->and($fields[0]->isSigned)->toBeTrue()
        ->and($fields[1]->isSigned)->toBeTrue();

    $report = app(SignatureValidator::class)->validate($pdf);

    expect($report->signatures)->toHaveCount(2)
        ->and($report->isValid())->toBeTrue();
});

it('draws the seal into the rectangle the template declared', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents(template(), 'contract.pdf')
        ->intoField('SignatureManager')
        ->seal()
        ->sign();

    // The widget's own /Rect survives, and the appearance is 170 by 50: the
    // box the template drew, not the configured default placement.
    expect($signed->contents)->toContain('/Rect[30 200 200 250]')
        ->and($signed->contents)->toContain('/BBox[0 0 170 50]');

    $fields = app(SignatureFieldReader::class)->read($signed->contents);

    expect($fields[0]->rectangle)->toBe([30.0, 200.0, 200.0, 250.0]);
});

it('refuses a field the document does not carry, naming the ones it does', function () {
    // Appending a field beside the one asked for would reproduce exactly the
    // failure intoField() exists to prevent, and would do it quietly.
    [$pfxPath, $password] = debugCertificate();

    expect(fn() => A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents(template(), 'contract.pdf')
        ->intoField('SignatureDirector')
        ->sign())
        ->toThrow(SignatureFieldException::class, 'SignatureManager, SignatureEmployee');
});

it('refuses a field that is already signed', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents(template(), 'contract.pdf')
        ->intoField('SignatureManager')
        ->sign();

    expect(fn() => A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents($signed->contents, 'contract.pdf')
        ->intoField('SignatureManager')
        ->sign())
        ->toThrow(SignatureFieldException::class, 'already signed');
});

it('refuses a placement alongside a field that has its own rectangle', function () {
    // One of them would have to win, and resolving it by precedence would
    // silently move the seal off the box the template drew.
    [$pfxPath, $password] = debugCertificate();

    expect(fn() => A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents(template(), 'contract.pdf')
        ->intoField('SignatureManager')
        ->seal(placement: new SealPlacement(x: 10, y: 10, width: 40))
        ->sign())
        ->toThrow(SignatureFieldException::class, 'cannot be given with intoField');
});

it('names no candidates when the document carries no field at all', function () {
    [$pfxPath, $password] = debugCertificate();

    expect(fn() => A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->intoField('SignatureManager')
        ->sign())
        ->toThrow(SignatureFieldException::class, 'it carries none');
});

it('decides visibility from the area the field actually has', function (
    float $x0,
    float $y0,
    float $x1,
    float $y1,
    bool $visible,
) {
    // A field with no area is invisible however it was written, and a field one
    // point wide still has one: the field's geometry is the template's
    // decision, and intoField() means honour the template.
    //
    // The corners arrive as four values rather than an array so their types are
    // the shape SignatureField declares, without a docblock saying so.
    expect(new SignatureField('Field', false, 9, 3, [$x0, $y0, $x1, $y1])->isVisible())->toBe($visible);
})->with([
    'empty' => [0.0, 0.0, 0.0, 0.0, false],
    'no width' => [30.0, 100.0, 30.0, 150.0, false],
    'no height' => [30.0, 100.0, 200.0, 100.0, false],
    'one point wide' => [30.0, 100.0, 31.0, 150.0, true],
    'one point tall' => [30.0, 100.0, 200.0, 101.0, true],
    'a box' => [30.0, 100.0, 200.0, 150.0, true],
]);

it('turns a field rectangle into the placement the seal is drawn with', function (
    float $x0,
    float $y0,
    float $x1,
    float $y1,
    float $x,
    float $y,
) {
    // ISO 32000-1 §7.9.5: a rectangle may be written with its corners in any
    // order, and an application is expected to normalise it. Two shapes are
    // needed here because in either one alone some corner happens to be the
    // minimum of both pairs, and reading the wrong one would go unnoticed.
    $placement = new SignatureField('Field', false, 9, 3, [$x0, $y0, $x1, $y1])->placement();

    expect($placement->x)->toBe($x)
        ->and($placement->y)->toBe($y)
        ->and($placement->width)->toBe(60.0)
        ->and($placement->height)->toBe(60.0);
})->with([
    'upper corner first' => [100.0, 70.0, 40.0, 10.0, 40.0, 10.0],
    'lower corner first' => [5.0, 10.0, 65.0, 70.0, 5.0, 10.0],
]);

it('follows an /AcroForm held as an indirect reference', function () {
    // Acrobat writes the form inline; other producers write it as a reference,
    // and a reader that only handles the inline case reports a template as
    // carrying no fields at all.
    $pdf = pdfWith([
        1 => '<</Type/Catalog/Pages 2 0 R/AcroForm 4 0 R>>',
        2 => '<</Type/Pages/Kids[]/Count 0>>',
        3 => '<</Type/Annot/Subtype/Widget/FT/Sig/T (Referenced)/Rect[0 0 10 10]>>',
        4 => '<</Fields[3 0 R]/SigFlags 3>>',
    ]);

    expect(app(SignatureFieldReader::class)->read($pdf))->toHaveCount(1);
});

it('reaches /Fields past a nested dictionary', function () {
    // Acrobat nests /DR inside /AcroForm, so a non-greedy match to the first
    // ">>" stops before /Fields is ever reached. Depth counting is what makes
    // this work.
    $pdf = pdfWith([
        1 => '<</Type/Catalog/Pages 2 0 R/AcroForm<</DR<</Font<</Helv 4 0 R>>>>/Fields[3 0 R]/SigFlags 3>>>>',
        2 => '<</Type/Pages/Kids[]/Count 0>>',
        3 => '<</Type/Annot/Subtype/Widget/FT/Sig/T (Nested)/Rect[0 0 10 10]>>',
        4 => '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
    ]);

    expect(app(SignatureFieldReader::class)->read($pdf))->toHaveCount(1);
});

it('skips the fields of a form that are not signature fields', function () {
    // A template usually carries text and checkbox fields beside its signature
    // fields, and /Fields lists them all.
    $pdf = pdfWith([
        1 => '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[3 0 R 4 0 R]/SigFlags 3>>>>',
        2 => '<</Type/Pages/Kids[]/Count 0>>',
        3 => '<</Type/Annot/Subtype/Widget/FT/Tx/T (FullName)/Rect[0 0 10 10]>>',
        4 => '<</Type/Annot/Subtype/Widget/FT/Sig/T (Signature)/Rect[0 0 10 10]>>',
    ]);

    $fields = app(SignatureFieldReader::class)->read($pdf);

    expect($fields)->toHaveCount(1)
        ->and($fields[0]->name)->toBe('Signature');
});

it('skips a signature field with no name, since a name is how it is addressed', function () {
    $pdf = pdfWith([
        1 => '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[3 0 R]/SigFlags 3>>>>',
        2 => '<</Type/Pages/Kids[]/Count 0>>',
        3 => '<</Type/Annot/Subtype/Widget/FT/Sig/Rect[0 0 10 10]>>',
    ]);

    expect(app(SignatureFieldReader::class)->read($pdf))->toBe([]);
});

it('reads a field name that carries escaped parentheses', function () {
    $pdf = pdfWith([
        1 => '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[3 0 R]/SigFlags 3>>>>',
        2 => '<</Type/Pages/Kids[]/Count 0>>',
        3 => '<</Type/Annot/Subtype/Widget/FT/Sig/T (Manager \\(deputy\\))/Rect[0 0 10 10]>>',
    ]);

    $fields = app(SignatureFieldReader::class)->read($pdf);

    expect($fields[0]->name)->toBe('Manager (deputy)');
});

it('reports a zero rectangle for a field that declares none', function () {
    $pdf = pdfWith([
        1 => '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[3 0 R]/SigFlags 3>>>>',
        2 => '<</Type/Pages/Kids[]/Count 0>>',
        3 => '<</Type/Annot/Subtype/Widget/FT/Sig/T (Invisible)>>',
    ]);

    $fields = app(SignatureFieldReader::class)->read($pdf);

    expect($fields[0]->rectangle)->toBe([0.0, 0.0, 0.0, 0.0])
        ->and($fields[0]->isVisible())->toBeFalse()
        ->and($fields[0]->pageNumber)->toBe(0);
});
