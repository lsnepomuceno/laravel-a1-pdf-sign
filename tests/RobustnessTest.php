<?php

use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\DocumentReader;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;
use LSNepomuceno\LaravelA1PdfSign\Support\PdfFilters;
use LSNepomuceno\LaravelA1PdfSign\Support\PngReader;
use LSNepomuceno\LaravelA1PdfSign\Validation\Asn1Reader;
use LSNepomuceno\LaravelA1PdfSign\Validation\PdfSignatureExtractor;
use LSNepomuceno\LaravelA1PdfSign\Validation\RevocationChecker;

/**
 * What the readers do with bytes nobody vetted.
 *
 * Everything under `Signing\Incremental`, `Validation` and the two stream
 * helpers parses input the application did not write: a PDF a user uploaded,
 * the CMS inside somebody else's signature, an OCSP response from a responder.
 * The contract for all of it is the same and is narrow: **read it, or throw the
 * documented exception**. Never a TypeError from an index that was not there,
 * never an unbounded loop, never a fatal.
 *
 * Corruption is generated from a fixed seed, so a failure is reproducible and a
 * green run means the same thing twice. It is not a fuzzer and does not pretend
 * to be one: a fuzzer explores, and this guards the shape of what the readers
 * are allowed to do when they fail.
 *
 * **Development and validation only**, like every other instrument here.
 *
 * See docs/spec/quality-policy.md.
 */
function corrupt(string $input, int $flips): string
{
    for ($flip = 0; $flip < $flips; $flip++) {
        $input[mt_rand(0, strlen($input) - 1)] = chr(mt_rand(0, 255));
    }

    return $input;
}

it('reads a corrupted document or refuses it, and does nothing else', function () {
    // 1,200 mutated documents across the four structural shapes the package
    // understands. Anything but InvalidPdfFileException escaping is the defect
    // this exists to catch.
    mt_srand(20260811);

    $seeds = [
        'classic table' => Files::read(resource('test.pdf')),
        'cross-reference stream' => Files::read(resource('xref-stream.pdf')),
        'object stream' => Files::read(resource('object-stream.pdf')),
        'form fields' => Files::read(resource('signature-fields.pdf')),
    ];

    $reader = app(DocumentReader::class);
    $extractor = app(PdfSignatureExtractor::class);
    $unexpected = [];

    foreach ($seeds as $shape => $original) {
        for ($round = 0; $round < 300; $round++) {
            $pdf = corrupt($original, mt_rand(1, 6));

            try {
                $reader->read($pdf);
            } catch (InvalidPdfFileException) {
                // The documented refusal.
            } catch (Throwable $thrown) {
                $unexpected[] = "{$shape}: " . $thrown::class;
            }

            try {
                // The extractor is handed whole files by the validator and has
                // no refusal of its own: it reports what it finds.
                $extractor->extract($pdf);
            } catch (Throwable $thrown) {
                $unexpected[] = "{$shape}, extracting: " . $thrown::class;
            }
        }
    }

    expect(array_values(array_unique($unexpected)))->toBe([]);
});

it('walks corrupted DER without falling over', function () {
    // The CMS comes out of a signature somebody else made, and the ASN.1 reader
    // is the thing standing between it and the rest of validation.
    mt_srand(20260811);

    $pdf = Files::read(sample('pades-b-t.pdf'));

    $trimmed = preg_match('/\/Contents <([0-9a-fA-F]+)>/', $pdf, $hex) === 1
        ? rtrim($hex[1], '0')
        : '';

    expect($trimmed)->not->toBe('');

    $der = (string) hex2bin(strlen($trimmed) % 2 === 1 ? $trimmed . '0' : $trimmed);

    $asn1 = new Asn1Reader();
    $unexpected = [];

    for ($round = 0; $round < 600; $round++) {
        $blob = corrupt($der, mt_rand(1, 8));

        try {
            $node = $asn1->at($blob);

            if ($node === null) {
                continue;
            }

            foreach ($asn1->childrenOf($blob, $node) as $child) {
                // The results are thrown away deliberately: what is under test
                // is that reading returns at all, whatever it returns.
                expect([
                    $asn1->oid($blob, $child),
                    $asn1->integerAsHex($blob, $child),
                    $asn1->generalizedTime($blob, $child),
                    $asn1->childrenOf($blob, $child),
                ])->toHaveCount(4);
            }
        } catch (Throwable $thrown) {
            $unexpected[] = $thrown::class . ': ' . $thrown->getMessage();
        }
    }

    expect(array_values(array_unique($unexpected)))->toBe([]);
});

it('answers unknown for corrupted revocation material rather than falling over', function () {
    // A response that has been damaged, or forged badly, must not be able to
    // reach anything but Unknown (docs/decisions/0024-revocation-is-evaluated-not-counted.md).
    mt_srand(20260811);

    $checker = new RevocationChecker();
    $issuers = [Files::read(resource('revocation/ca.pem'))];
    $ocsp = Files::read(resource('revocation/ocsp-good.der'));
    $crl = Files::read(resource('revocation/crl-revoked.der'));
    $unexpected = [];

    for ($round = 0; $round < 300; $round++) {
        try {
            $checker->status('1234', [corrupt($ocsp, mt_rand(1, 6))], [corrupt($crl, mt_rand(1, 6))], $issuers);
        } catch (Throwable $thrown) {
            $unexpected[] = $thrown::class . ': ' . $thrown->getMessage();
        }
    }

    expect(array_values(array_unique($unexpected)))->toBe([]);
});

it('decodes a corrupted stream or answers null, and does nothing else', function () {
    // The filters read payloads out of documents the application did not write,
    // and a decoder that throws on bad input turns an unreadable object into an
    // unsignable document (docs/decisions/0020-decode-the-filters-documents-use.md).
    mt_srand(20260811);

    $filters = new PdfFilters();
    $flate = (string) gzcompress(str_repeat('the payload, compressed. ', 40));
    $unexpected = [];

    foreach ([
        '<</Filter/FlateDecode>>',
        '<</Filter/FlateDecode/DecodeParms<</Predictor 12/Columns 5>>>>',
        '<</Filter [/ASCIIHexDecode /FlateDecode]>>',
        '<</Filter/LZWDecode>>',
        '<</Filter/ASCII85Decode>>',
        '<</Filter/RunLengthDecode>>',
    ] as $dictionary) {
        for ($round = 0; $round < 150; $round++) {
            try {
                $filters->decode(corrupt($flate, mt_rand(1, 10)), $dictionary);
            } catch (Throwable $thrown) {
                $unexpected[] = $dictionary . ': ' . $thrown::class;
            }
        }
    }

    expect(array_values(array_unique($unexpected)))->toBe([]);
});

it('reads a corrupted PNG or answers null, and does nothing else', function () {
    // The seal renderer hands this whatever the encoder produced, and a caller
    // may point sealFrom() at any file at all
    // (docs/decisions/0023-a-seal-that-can-be-transparent.md).
    mt_srand(20260811);

    $png = Files::read(dirname(__DIR__) . '/src/Resources/img/sign-seal.png');
    $reader = new PngReader();
    $unexpected = [];

    for ($round = 0; $round < 200; $round++) {
        try {
            $reader->planes(corrupt($png, mt_rand(1, 12)));
        } catch (Throwable $thrown) {
            $unexpected[] = $thrown::class . ': ' . $thrown->getMessage();
        }
    }

    expect(array_values(array_unique($unexpected)))->toBe([]);
});
