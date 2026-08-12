<?php

use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Data\FieldLock;
use LSNepomuceno\LaravelA1PdfSign\Enums\FieldLockAction;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\FieldLockException;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\FieldLockReader;

/**
 * Signature field locks, ISO 32000-1 §12.7.4.5 and §12.8.2.4.
 *
 * The template carries two empty fields, SignatureManager and
 * SignatureEmployee, which is what makes locking observable: a lock is only
 * interesting when there is a second field left to fill.
 *
 * See docs/decisions/0021-locking-fields-and-honouring-locks.md.
 */
function signedWithLock(?FieldLock $lock, string $field = 'SignatureManager'): string
{
    [$pfxPath, $password] = debugCertificate();

    $pending = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents(template(), 'contract.pdf')
        ->intoField($field);

    if ($lock !== null) {
        $pending->lock($lock);
    }

    return $pending->sign()->contents;
}

it('writes the lock onto the field and the transform that enforces it', function () {
    // Both, or neither. The widget's /Lock is what a reader shows; the
    // /FieldMDP transform is what it enforces. A document carrying only the
    // first says the fields are locked and lets them be filled anyway.
    $pdf = signedWithLock(FieldLock::all());

    expect($pdf)->toContain('/Lock <</Type/SigFieldLock/Action/All>>')
        ->and($pdf)->toContain('/TransformMethod/FieldMDP')
        ->and($pdf)->toContain('/TransformParams<</Type/TransformParams/Action/All/V/1.2>>')
        // §12.8.2.4: /Data names the object the transform applies to.
        ->and($pdf)->toMatch('#/Data \d+ 0 R#');
});

it('names the fields an include lock covers', function () {
    $pdf = signedWithLock(FieldLock::only(['SignatureEmployee']));

    expect($pdf)->toContain('/Lock <</Type/SigFieldLock/Action/Include/Fields[(SignatureEmployee)]>>')
        ->and($pdf)->toContain('/Action/Include/Fields[(SignatureEmployee)]');
});

it('refuses to fill a field an earlier signature locked', function () {
    // The alternative is a document whose first signature every reader reports
    // as broken, discovered by the caller from the reader rather than from here.
    [$pfxPath, $password] = debugCertificate();

    expect(fn() => A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents(signedWithLock(FieldLock::all()), 'contract.pdf')
        ->intoField('SignatureEmployee')
        ->sign())
        ->toThrow(FieldLockException::class, 'was locked by the signature in "SignatureManager"');
});

it('lets a field outside the lock be signed afterwards', function () {
    // The refusal must not be blanket, or /Include would mean the same as /All.
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents(signedWithLock(FieldLock::only(['Amount'])), 'contract.pdf')
        ->intoField('SignatureEmployee')
        ->sign();

    $report = app(SignatureValidator::class)->validate($signed->contents);

    expect($report->signatures)->toHaveCount(2)
        ->and($report->isValid())->toBeTrue();
});

it('reads an exclude lock as covering everything it does not name', function () {
    [$pfxPath, $password] = debugCertificate();

    // Everything except SignatureEmployee, so that one is still fillable.
    $locked = signedWithLock(FieldLock::except(['SignatureEmployee']));

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents($locked, 'contract.pdf')
        ->intoField('SignatureEmployee')
        ->sign();

    expect(app(SignatureValidator::class)->validate($signed->contents)->isValid())->toBeTrue();

    // And a name it does not exempt is refused.
    $reader = app(FieldLockReader::class);

    expect($reader->lockOn($locked, 'Amount'))->toBe('SignatureManager')
        ->and($reader->lockOn($locked, 'SignatureEmployee'))->toBeNull();
});

it('ignores a lock on a field nobody has signed yet', function () {
    // A /Lock on an unsigned field states what will happen when it is signed,
    // not what is true now. Reading it as in force would make a template that
    // ships such a field unsignable.
    $reader = app(FieldLockReader::class);

    expect($reader->inForce(template()))->toBe([])
        ->and($reader->lockOn(template(), 'SignatureManager'))->toBeNull();
});

it('does not let a lock lock the field that imposed it', function () {
    // The signature that carries the lock has already filled its own field, so
    // reporting it as locked would describe a conflict that cannot arise.
    $locked = signedWithLock(FieldLock::all());

    expect(app(FieldLockReader::class)->lockOn($locked, 'SignatureManager'))->toBeNull();
});

it('reads back the lock it wrote', function (FieldLock $lock, FieldLockAction $action, array $fields) {
    $locks = app(FieldLockReader::class)->inForce(signedWithLock($lock));

    expect($locks)->toHaveKey('SignatureManager')
        ->and($locks['SignatureManager']->action)->toBe($action)
        ->and($locks['SignatureManager']->fields)->toBe($fields);
})->with([
    'all' => [FieldLock::all(), FieldLockAction::All, []],
    'include' => [FieldLock::only(['A', 'B']), FieldLockAction::Include, ['A', 'B']],
    'exclude' => [FieldLock::except(['C']), FieldLockAction::Exclude, ['C']],
]);

it('refuses a lock that would lock nothing, or everything by accident', function () {
    // /Include with no fields locks nothing; /Exclude with no fields locks
    // every field there is. The second is the expensive one to discover late.
    [$pfxPath, $password] = debugCertificate();

    $sign = fn(FieldLock $lock) => A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents(template(), 'contract.pdf')
        ->intoField('SignatureManager')
        ->lock($lock)
        ->sign();

    expect(fn() => $sign(new FieldLock(FieldLockAction::Include)))
        ->toThrow(FieldLockException::class, 'needs the fields it applies to')
        ->and(fn() => $sign(new FieldLock(FieldLockAction::Exclude)))
        ->toThrow(FieldLockException::class, 'needs the fields it applies to');
});

it('locks everything when lock() is called with no argument', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents(template(), 'contract.pdf')
        ->intoField('SignatureManager')
        ->lock()
        ->sign();

    expect($signed->contents)->toContain('/Action/All');
});

it('leaves an unlocked signature without a /Lock or a FieldMDP transform', function () {
    $pdf = signedWithLock(null);

    expect($pdf)->not->toContain('/Lock')
        ->and($pdf)->not->toContain('/FieldMDP');
});

it('answers which fields a lock covers', function () {
    expect(FieldLock::all()->covers('anything'))->toBeTrue()
        ->and(FieldLock::only(['A'])->covers('A'))->toBeTrue()
        ->and(FieldLock::only(['A'])->covers('B'))->toBeFalse()
        ->and(FieldLock::except(['A'])->covers('A'))->toBeFalse()
        ->and(FieldLock::except(['A'])->covers('B'))->toBeTrue();
});

it('escapes a field name that carries parentheses', function () {
    // A literal string ends at the first unescaped ")", so a name containing
    // one would end the /Fields array early and leave the rest as syntax.
    $lock = FieldLock::only(['Sign (here)']);

    expect($lock->toDictionary())->toBe('<</Type/SigFieldLock/Action/Include/Fields[(Sign \(here\))]>>');
});

it('keeps a certification and a lock in one /Reference array', function () {
    // A signature may certify the document and lock fields at once. Two
    // /Reference entries would leave a reader to pick one.
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->certify()
        ->lock()
        ->sign();

    expect(preg_match_all('#/Reference\[#', $signed->contents))->toBe(1)
        ->and($signed->contents)->toContain('/TransformMethod/DocMDP')
        ->and($signed->contents)->toContain('/TransformMethod/FieldMDP');

    $report = app(SignatureValidator::class)->validate($signed->contents);

    // The certification still reads back: a FieldMDP transform beside it must
    // not be mistaken for one, which is the trap 0012 already guards.
    expect($report->isCertified())->toBeTrue()
        ->and($report->isValid())->toBeTrue();
});
