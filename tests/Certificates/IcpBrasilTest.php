<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Certificates\IcpBrasilReader;
use LSNepomuceno\LaravelA1PdfSign\Certificates\NativeCertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Certificates\SubjectAlternativeNameReader;
use LSNepomuceno\LaravelA1PdfSign\Enums\IcpBrasilCertificateType;
use LSNepomuceno\LaravelA1PdfSign\Enums\IcpBrasilFinding;
use LSNepomuceno\LaravelA1PdfSign\Enums\IcpBrasilOtherName;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;
use LSNepomuceno\LaravelA1PdfSign\Support\NationalRegistry;
use LSNepomuceno\LaravelA1PdfSign\Testing\DebugCertificate;
use LSNepomuceno\LaravelA1PdfSign\Validation\IcpBrasilValidator;

/**
 * Reading who a Brazilian signer is, and checking the certificate says it
 * consistently.
 *
 * Everything here runs against certificates this suite generates, which are
 * self-signed and carry the ICP-Brasil fields. That is the whole shape of the
 * claim being tested: the fields can be read and judged from the bytes, and no
 * amount of judging them makes a certificate trustworthy
 * (docs/decisions/0029-the-identity-a-brazilian-signer-is-known-by.md).
 */

/**
 * The leaf certificate of a throwaway ICP-Brasil bundle, as PEM.
 *
 * @param  array<string, string>  $otherNames
 */
function icpCertificate(
    IcpBrasilCertificateType $type = IcpBrasilCertificateType::Individual,
    array $otherNames = [],
    string $commonName = 'JOAO DA SILVA:11144477735',
): string {
    [$pfx, $password] = DebugCertificate::icpBrasil($type, $otherNames, $commonName);

    return app(NativeCertificateReader::class)->read($pfx, $password)->original;
}

it('finds the otherName fields openssl_x509_parse cannot render', function () {
    // The reason this reader exists at all: PHP renders every one of these as
    // `othername:<unsupported>`, so the identity was unreachable through the
    // parse the rest of the package uses.
    $certificate = icpCertificate();

    $parsed = openssl_x509_parse($certificate);
    $extensions = is_array($parsed) && is_array($parsed['extensions'] ?? null) ? $parsed['extensions'] : [];
    $rendered = is_string($extensions['subjectAltName'] ?? null) ? $extensions['subjectAltName'] : '';

    $names = app(SubjectAlternativeNameReader::class)->otherNames($certificate);

    expect($rendered)->toContain('othername')
        ->and($names)->toHaveKeys([
            IcpBrasilOtherName::HolderData->value,
            IcpBrasilOtherName::VoterRegistration->value,
            IcpBrasilOtherName::HolderSocialSecurity->value,
        ]);
});

it('reads an e-CPF holder', function () {
    $identity = app(IcpBrasilReader::class)->read(icpCertificate());

    expect($identity->type)->toBe(IcpBrasilCertificateType::Individual)
        ->and($identity->cpf)->toBe('11144477735')
        ->and($identity->cnpj)->toBeNull()
        ->and($identity->birthDate)->toBe('11/08/1985')
        ->and($identity->socialIdentity)->toBe('12345678901')
        // Written padded to fifteen characters, handed back as the number.
        ->and($identity->nationalId)->toBe('12345678')
        ->and($identity->nationalIdIssuer)->toBe('SSPSP')
        ->and($identity->voterRegistration)->toBe('465555610469')
        ->and($identity->voterMunicipality)->toBe('SAOPAULOSP');
});

it('reads an e-CNPJ as the company rather than as the person answering for it', function () {
    // An e-CNPJ carries a CPF too. Reading the CPF first would report every
    // company certificate as a person, which is the trap in the layout.
    $identity = app(IcpBrasilReader::class)->read(icpCertificate(IcpBrasilCertificateType::LegalEntity));

    expect($identity->type)->toBe(IcpBrasilCertificateType::LegalEntity)
        ->and($identity->cnpj)->toBe('11222333000181')
        ->and($identity->cpf)->toBe('11144477735')
        ->and($identity->responsibleName)->toBe('JOAO DA SILVA')
        ->and($identity->registry())->toBe('11222333000181')
        ->and($identity->formattedRegistry())->toBe('11.222.333/0001-81');
});

it('reports a field written as zeros as absent rather than as zeros', function () {
    // The specification requires an unavailable number to be filled with zeros,
    // so "000000000000" and "not there" are the same fact, and only one of them
    // is worth handing to a caller.
    $identity = app(IcpBrasilReader::class)->read(icpCertificate());

    expect($identity->socialSecurity)->toBeNull();
});

it('answers None for a certificate that never claimed to be ICP-Brasil', function () {
    [$pfx, $password] = debugCertificate();

    $identity = app(IcpBrasilReader::class)
        ->read(app(NativeCertificateReader::class)->read(Files::read($pfx), $password)->original);

    expect($identity->type)->toBe(IcpBrasilCertificateType::None)
        ->and($identity->cpf)->toBeNull();
});

it('carries the identity on the signer a validated document reports', function () {
    // The point of the whole feature: validate a document and know who signed
    // it, by the number they are known by in Brazil.
    [$pfx, $password] = DebugCertificate::icpBrasil();

    $path = A1PdfSign::tempPath(true, '.pfx');
    file_put_contents($path, $pfx);

    $signed = A1PdfSign::newSignature()
        ->certificate($path, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->save(A1PdfSign::tempPath(true, '.pdf'));

    $signer = A1PdfSign::validate($path = $signed)->signers()[0];

    expect($signer->icpBrasil?->cpf)->toBe('11144477735')
        // The name without the CPF glued to it, which is what a caller wants to
        // show and would otherwise have to strip themselves.
        ->and($signer->name())->toBe('JOAO DA SILVA')
        ->and($signer->commonName)->toBe('JOAO DA SILVA:11144477735');

    unlink($path);
});

/*
|--------------------------------------------------------------------------
| The structural check
|--------------------------------------------------------------------------
*/

it('conforms when every rule the specification states is satisfied', function () {
    $report = app(IcpBrasilValidator::class)->validate(icpCertificate(), 'JOAO DA SILVA:11144477735');

    expect($report->conforms())->toBeTrue()
        ->and($report->findings)->toBe([])
        ->and($report->identity->cpf)->toBe('11144477735');
});

it('does not conform when the certificate is not ICP-Brasil at all', function () {
    // Not a malformed ICP-Brasil certificate: one that never claimed to be one.
    // The type is what separates those, and conforms() is false either way
    // because there was nothing to conform to.
    [$pfx, $password] = debugCertificate();

    $report = app(IcpBrasilValidator::class)->validate(
        app(NativeCertificateReader::class)->read(Files::read($pfx), $password)->original,
    );

    expect($report->conforms())->toBeFalse()
        ->and($report->findings)->toBe([])
        ->and($report->identity->type)->toBe(IcpBrasilCertificateType::None);
});

it('catches a CPF that does not satisfy its own check digits', function () {
    $broken = '11081985' . '11144477736' . '12345678901' . '000000012345678' . 'SSPSP';

    $report = app(IcpBrasilValidator::class)->validate(
        icpCertificate(otherNames: [IcpBrasilOtherName::HolderData->value => $broken]),
    );

    expect($report->has(IcpBrasilFinding::InvalidCpfCheckDigits))->toBeTrue()
        ->and($report->conforms())->toBeFalse();
});

it('catches a CNPJ that does not satisfy its own check digits', function () {
    $report = app(IcpBrasilValidator::class)->validate(
        icpCertificate(
            IcpBrasilCertificateType::LegalEntity,
            [IcpBrasilOtherName::CompanyRegistry->value => '11222333000182'],
        ),
    );

    expect($report->has(IcpBrasilFinding::InvalidCnpjCheckDigits))->toBeTrue();
});

it('catches a required field the profile does not carry', function () {
    // An e-CPF must carry all three. Removing the voter registration leaves a
    // certificate that reads as an e-CPF and is not a complete one.
    $report = app(IcpBrasilValidator::class)->validate(
        icpCertificate(otherNames: [IcpBrasilOtherName::VoterRegistration->value => '']),
    );

    expect($report->has(IcpBrasilFinding::MissingRequiredField))->toBeTrue();
});

it('catches a field that is not the width its layout fixes', function () {
    $report = app(IcpBrasilValidator::class)->validate(
        icpCertificate(otherNames: [IcpBrasilOtherName::HolderData->value => '1108198511144477735']),
    );

    expect($report->has(IcpBrasilFinding::UnexpectedFieldLength))->toBeTrue();
});

it('accepts the one short field the specification allows', function () {
    // "The 6 positions for the RG issuer refer to the maximum size, and only the
    // positions needed are used", so the last field of a layout is allowed to
    // run short and only that one.
    $short = '11081985' . '11144477735' . '12345678901' . '000000012345678' . 'DIC';

    $report = app(IcpBrasilValidator::class)->validate(
        icpCertificate(otherNames: [IcpBrasilOtherName::HolderData->value => $short]),
    );

    expect($report->has(IcpBrasilFinding::UnexpectedFieldLength))->toBeFalse()
        ->and($report->identity->nationalIdIssuer)->toBe('DIC');
});

it('catches characters the alphabet does not allow', function () {
    // Only A to Z and 0 to 9. Accents are stripped before issue precisely
    // because of this rule, so one appearing is a certificate built by hand.
    $report = app(IcpBrasilValidator::class)->validate(
        icpCertificate(
            IcpBrasilCertificateType::LegalEntity,
            [IcpBrasilOtherName::ResponsibleName->value => 'JOAO DA SILVA JUNIOR-ME'],
        ),
    );

    expect($report->has(IcpBrasilFinding::IllegalCharacter))->toBeTrue();
});

it('catches a birth date that is not a date', function () {
    $report = app(IcpBrasilValidator::class)->validate(
        icpCertificate(otherNames: [
            IcpBrasilOtherName::HolderData->value => '32131985' . '11144477735' . '12345678901' . '000000012345678' . 'SSPSP',
        ]),
    );

    expect($report->has(IcpBrasilFinding::ImplausibleBirthDate))->toBeTrue()
        ->and($report->identity->birthDate)->toBeNull();
});

it('catches an issuing authority named for an identity number that is absent', function () {
    // §3.2.5, stated outright: if the RG is unavailable, the issuer and state
    // fields shall not be filled in.
    $report = app(IcpBrasilValidator::class)->validate(
        icpCertificate(otherNames: [
            IcpBrasilOtherName::HolderData->value => '11081985' . '11144477735' . '12345678901' . '000000000000000' . 'SSPSP',
        ]),
    );

    expect($report->has(IcpBrasilFinding::IssuerNamedWithoutNationalId))->toBeTrue();
});

it('catches the two places a CPF appears disagreeing', function () {
    // Nothing in the format makes the common name and the extension agree, and
    // a document filed under the wrong number is filed under the wrong person.
    $report = app(IcpBrasilValidator::class)->validate(
        icpCertificate(commonName: 'JOAO DA SILVA:12345678909'),
        'JOAO DA SILVA:12345678909',
    );

    expect($report->has(IcpBrasilFinding::CommonNameDisagreesWithCpf))->toBeTrue()
        ->and($report->messages())->toContain('commonName: the CPF in the common name is not the CPF in the subject alternative name (12345678909 against 11144477735)');
});

it('checks a certificate through the facade, from the PFX a signer already has', function () {
    [$pfx, $password] = DebugCertificate::icpBrasil();

    $path = A1PdfSign::tempPath(true, '.pfx');
    file_put_contents($path, $pfx);

    $report = A1PdfSign::icpBrasil($path, $password);

    expect($report->conforms())->toBeTrue()
        ->and($report->identity->cpf)->toBe('11144477735');

    unlink($path);
});

/*
|--------------------------------------------------------------------------
| The arithmetic underneath
|--------------------------------------------------------------------------
*/

it('agrees with the check digits every Brazilian document carries', function (string $number, bool $valid) {
    expect(app(NationalRegistry::class)->isCpf($number))->toBe($valid);
})->with([
    ['11144477735', true],
    ['12345678909', true],
    // One digit changed, which is the error the check digits exist to catch.
    ['11144477736', false],
    // Arithmetically consistent and rejected everywhere in Brazil.
    ['11111111111', false],
    ['00000000000', false],
    ['1114447773', false],
    ['abcdefghijk', false],
]);

it('agrees with the check digits a CNPJ carries', function (string $number, bool $valid) {
    expect(app(NationalRegistry::class)->isCnpj($number))->toBe($valid);
})->with([
    ['11222333000181', true],
    ['33989214000191', true],
    ['11222333000182', false],
    ['11111111111111', false],
    ['1122233300018', false],
]);
