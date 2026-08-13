<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\SealPlacementException;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\DocumentReader;

/**
 * Which page the seal lands on, ISO 32000-1 §7.7.3.2 and §12.5.6.12.
 *
 * SealPlacement has carried $page and $onEveryPage since 2.0 and nothing read
 * either of them: every seal went onto the first page the cross-reference table
 * happened to mention. See docs/decisions/0017-the-seal-goes-where-it-was-asked-for.md.
 */
function sealedOn(?int $page = null, bool $onEveryPage = false): string
{
    [$pdf, $pages] = reversedPages();
    [$pfxPath, $password] = debugCertificate();

    $placement = new SealPlacement(
        width: 40,
        page: $page ?? SealPlacement::LAST_PAGE,
        onEveryPage: $onEveryPage,
    );

    return A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents($pdf, 'contract.pdf')
        ->seal(placement: $placement)
        ->sign()
        ->contents;
}

/**
 * The object numbers of the pages carrying an annotation, in tree order.
 *
 * @return list<int>
 */
function annotatedPages(string $pdf): array
{
    [, $pages] = reversedPages();

    preg_match_all('/(\d+) 0 obj\s*(.*?)endobj/s', $pdf, $objects, PREG_SET_ORDER);

    $annotated = [];

    foreach ($objects as [, $number, $body]) {
        if (str_contains($body, '/Annots')) {
            $annotated[] = (int) $number;
        }
    }

    return array_values(array_filter($pages, static fn(int $number): bool => in_array($number, $annotated, true)));
}

it('reads the pages in the order the tree declares, not the order they were written', function () {
    // Object numbers carry no page order: a producer is free to write the last
    // page first, and this fixture does exactly that.
    [$pdf, $pages] = reversedPages();

    $reader = app(DocumentReader::class);

    expect($reader->pages($pdf, $reader->read($pdf)))->toBe($pages)
        ->and($pages)->toBe([5, 4, 3]);
});

it('reports the first page of the tree, not the lowest-numbered page object', function () {
    // The scan this replaced walked the cross-reference table in number order
    // and would answer 3, which is the last page.
    [$pdf] = reversedPages();

    $reader = app(DocumentReader::class);

    expect($reader->findFirstPage($pdf, $reader->read($pdf)))->toBe(5);
});

it('puts the seal on the page that was asked for', function () {
    // The defect this closes: page 2 produced a widget on page 1, silently, in
    // a parameter the documentation tells callers to use.
    expect(sealedOn(page: 2))->toContain('/P 4 0 R');
});

it('puts the seal on the last page when the placement says so', function () {
    expect(sealedOn(page: SealPlacement::LAST_PAGE))->toContain('/P 3 0 R');
});

it('defaults to the last page, which is what SealPlacement has always declared', function () {
    // Not a free choice: SealPlacement::$page defaults to LAST_PAGE, so a caller
    // who passes no page has already asked for the last one.
    [$pfxPath, $password] = debugCertificate();
    [$pdf] = reversedPages();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents($pdf, 'contract.pdf')
        ->seal()
        ->sign();

    expect($signed->contents)->toContain('/P 3 0 R');
});

it('leaves an invisible signature on the first page', function () {
    // Without a seal there is no appearance to place, so the widget only has to
    // sit somewhere legal, and the first page is where it has always sat.
    [$pfxPath, $password] = debugCertificate();
    [$pdf] = reversedPages();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents($pdf, 'contract.pdf')
        ->sign();

    expect($signed->contents)->toContain('/P 5 0 R');
});

it('marks every page when the placement asks for it', function () {
    $pdf = sealedOn(onEveryPage: true);

    // One widget, because a signature is one field, and a stamp on each of the
    // other pages drawing the same appearance.
    expect(preg_match_all('/\/Subtype\/Widget/', $pdf))->toBe(1)
        ->and(preg_match_all('/\/Subtype\/Stamp/', $pdf))->toBe(2)
        ->and(annotatedPages($pdf))->toBe([5, 4, 3]);
});

it('draws every stamp from the one form the seal already produced', function () {
    // A copy of the image per page would multiply the JPEG by the page count.
    $pdf = sealedOn(onEveryPage: true);

    $form = preg_match('/(\d+) 0 obj\n<<\/Type\/XObject\/Subtype\/Form/', $pdf, $matches) === 1
        ? $matches[1]
        : '';

    // Two image objects, because a transparent seal keeps its alpha channel in
    // a separate /SMask, and one form that both the widget and the stamps draw
    // (docs/decisions/0023-a-seal-that-can-be-transparent.md).
    expect(preg_match_all('/\/Subtype\/Image/', $pdf))->toBe(2)
        ->and(preg_match_all('/\/SMask \d+ 0 R/', $pdf))->toBe(1)
        ->and(preg_match_all('/\/Subtype\/Form/', $pdf))->toBe(1)
        ->and($form)->not->toBe('')
        // The widget and both stamps name that one form.
        ->and(preg_match_all("/\/AP<<\/N {$form} 0 R>>/", $pdf))->toBe(3);
});

it('touches only the page it seals', function () {
    // A revision that rewrote every page would be a revision the size of the
    // document, and each rewritten page is a page an earlier signature covered.
    expect(annotatedPages(sealedOn(page: 2)))->toBe([4]);
});

it('refuses a page the document does not have', function () {
    // Clamping to the last page is the quiet answer, and quiet is the defect
    // this whole record exists to remove.
    expect(fn() => sealedOn(page: 7))
        ->toThrow(SealPlacementException::class, 'the seal was placed on page 7, but the document has 3 pages');
});

it('refuses page zero, since pages are counted from one', function () {
    expect(fn() => sealedOn(page: 0))->toThrow(SealPlacementException::class);
});

it('keeps the signature valid when the seal spans every page', function () {
    // The stamps are written in the signature's own revision, so they fall
    // inside /ByteRange and the signature covers them.
    $report = app(SignatureValidator::class)->validate(sealedOn(onEveryPage: true));

    expect($report->isValid())->toBeTrue()
        ->and($report->signatures)->toHaveCount(1)
        ->and($report->signatures[0]->coversWholeDocument)->toBeTrue();
});

it('gives each signature its own page', function () {
    // The seal belongs to a signature, not to the document, which is what the
    // documentation promises and what two revisions have to keep true.
    [$pfxPath, $password] = debugCertificate();
    [$pdf] = reversedPages();

    $first = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents($pdf, 'contract.pdf')
        ->seal(placement: new SealPlacement(width: 40, page: 1))
        ->sign();

    $second = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents($first->contents, 'contract.pdf')
        ->seal(placement: new SealPlacement(width: 40, page: 3))
        ->sign();

    // The first revision survives byte for byte, so both /P entries are present
    // and they name different pages.
    expect(substr($second->contents, 0, $first->size()))->toBe($first->contents)
        ->and($second->contents)->toContain('/P 5 0 R')
        ->and($second->contents)->toContain('/P 3 0 R')
        ->and(app(SignatureValidator::class)->validate($second->contents)->isValid())->toBeTrue();
});

it('answers which pages a placement applies to', function () {
    $last = new SealPlacement(page: SealPlacement::LAST_PAGE);

    expect($last->appliesTo(3, 3))->toBeTrue()
        ->and($last->appliesTo(1, 3))->toBeFalse()
        ->and(new SealPlacement(page: 2)->appliesTo(2, 3))->toBeTrue()
        ->and(new SealPlacement(page: 2)->appliesTo(3, 3))->toBeFalse()
        ->and(new SealPlacement(onEveryPage: true)->appliesTo(2, 3))->toBeTrue()
        // onEveryPage wins over a page that names one of them.
        ->and(new SealPlacement(page: 1, onEveryPage: true)->appliesTo(3, 3))->toBeTrue();
});

it('names the page count it measured against', function () {
    expect(SealPlacementException::pageOutOfRange(4, 1)->getMessage())
        ->toBe('the seal was placed on page 4, but the document has 1 page')
        ->and((string) SealPlacementException::pageOutOfRange(4, 1))
        ->toContain('the seal was placed on page 4');
});
