<?php

use LSNepomuceno\LaravelA1PdfSign\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every test in this directory runs against Testbench's application harness,
| with the package's service provider registered. See tests/TestCase.php.
|
*/

uses(TestCase::class)->in(__DIR__);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Generates a throwaway PFX certificate and returns its path and password.
 *
 * @return array{0: string, 1: string}
 */
function debugCertificate(): array
{
    [$pfx, $password] = LSNepomuceno\LaravelA1PdfSign\Testing\DebugCertificate::make();

    $path = LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign::tempPath(true, '.pfx');
    file_put_contents($path, $pfx);

    return [$path, $password];
}

/**
 * Generates a throwaway PEM certificate on disk, in both shapes the entry point
 * accepts: certificate and key as separate files, and the two combined.
 *
 * @return array{0: string, 1: string, 2: string, 3: string} Certificate path, private key
 *                                                           path, combined bundle path, and
 *                                                           the key's password, empty when
 *                                                           it is unencrypted.
 */
function pemCertificate(bool $encryptKey = true): array
{
    [$certificate, $privateKey, $password] = LSNepomuceno\LaravelA1PdfSign\Testing\DebugCertificate::makePem($encryptKey);

    $certificatePath = LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign::tempPath(true, '.pem');
    $privateKeyPath = LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign::tempPath(true, '.key');
    $bundlePath = LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign::tempPath(true, '.pem');

    file_put_contents($certificatePath, $certificate);
    file_put_contents($privateKeyPath, $privateKey);
    file_put_contents($bundlePath, $certificate . $privateKey);

    return [$certificatePath, $privateKeyPath, $bundlePath, $password];
}

/**
 * Reads a throwaway certificate straight into the object the signer expects.
 *
 * Defined here rather than in a test file: helpers that live in one test file
 * are invisible to the others once the suite runs in parallel.
 */
function testCertificate(): LSNepomuceno\LaravelA1PdfSign\Data\Certificate
{
    [$pfx, $password] = LSNepomuceno\LaravelA1PdfSign\Testing\DebugCertificate::make();

    return app(LSNepomuceno\LaravelA1PdfSign\Certificates\NativeCertificateReader::class)
        ->read($pfx, $password);
}

/**
 * Absolute path of a file under tests/Resources.
 */
function resource(string $name): string
{
    return __DIR__ . '/Resources/' . $name;
}

/**
 * Absolute path of a committed sample.
 *
 * The samples are the package's own output, kept for validation in real
 * readers. Reading them back in the suite is the same principle one step
 * further: a document produced at pades-b-t carries a token from a real
 * authority, and no fixture that can be generated offline does
 * (docs/decisions/0019-validation-reads-what-it-writes.md).
 */
function sample(string $name): string
{
    return __DIR__ . '/../samples/' . $name;
}

/**
 * The contract template: two empty signature fields, laid out by someone else.
 *
 * See docs/decisions/0013-signing-into-an-existing-field.md.
 */
function template(): string
{
    return LSNepomuceno\LaravelA1PdfSign\Support\Files::read(resource('signature-fields.pdf'));
}

/**
 * A document of $count pages whose tree order is the reverse of its object
 * numbering.
 *
 * Deliberately reversed. A fixture numbered in reading order cannot tell a page
 * tree walk apart from a scan of the cross-reference table, and the scan is what
 * used to answer here: object 3 is the *last* page, so anything reading page
 * order out of object numbers reports it as the first
 * (docs/decisions/0017-the-seal-goes-where-it-was-asked-for.md).
 *
 * @return array{0: string, 1: list<int>} The document, and its page object
 *                                        numbers in reading order.
 */
function reversedPages(int $count = 3): array
{
    $numbers = range($count + 2, 3);

    $objects = [
        1 => '<</Type/Catalog/Pages 2 0 R>>',
        2 => '<</Type/Pages/Kids['
            . implode(' ', array_map(static fn(int $number): string => "{$number} 0 R", $numbers))
            . "]/Count {$count}>>",
    ];

    foreach ($numbers as $number) {
        $objects[$number] = '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>';
    }

    return [pdfWith($objects), $numbers];
}

/**
 * What qpdf says about a file.
 *
 * Here rather than in a test file because a second one needs it now, and a
 * helper defined inside one is invisible to the others under --parallel.
 *
 * **A development and validation instrument only.** qpdf is never invoked by
 * `src/`, and `tests/ArchTest.php` fails if that changes: a consuming
 * application installs a signing library, not a toolchain.
 */
function qpdfCheck(string $path, #[SensitiveParameter] string $password = ''): string
{
    // qpdf exits non-zero for warnings and for errors alike, which are two
    // different things, so the verdict is read from the output rather than from
    // the status.
    return app(LSNepomuceno\LaravelA1PdfSign\Support\ProcessRunner::class)->run(sprintf(
        'qpdf --check %s %s 2>&1 || true',
        $password === '' ? '' : '--password=' . escapeshellarg($password),
        escapeshellarg($path),
    ));
}

/**
 * The complaints qpdf has about a file, with the offsets taken out.
 *
 * Offsets move when a revision is appended, which is the whole point, so a
 * warning that says the same thing about the same object has to compare equal
 * before and after.
 *
 * @return list<string>
 */
function qpdfComplaints(string $contents): array
{
    $found = [];

    foreach (explode("\n", $contents) as $line) {
        if (preg_match('/^(WARNING|ERROR):/', trim($line)) !== 1) {
            continue;
        }

        // "…, object 3 0 at offset 34353: kid 0 …" keeps the object and the
        // complaint, and loses the position.
        $found[] = trim((string) preg_replace(
            ['/ at offset \d+/', '/^[^,]*, /'],
            ['', ''],
            trim($line),
        ));
    }

    sort($found);

    return array_values(array_unique($found));
}

/**
 * @return list<string>
 */
function qpdfComplaintsAbout(string $path, #[SensitiveParameter] string $password = ''): array
{
    return qpdfComplaints(qpdfCheck($path, $password));
}

/**
 * veraPDF's verdict on a file, as PASS or FAIL.
 *
 * Here rather than in a test file because two of them need it, and a helper
 * defined inside one is invisible to the others under --parallel.
 */
function veraPdfVerdict(string $path, string $flavour): string
{
    // "|| true" because veraPDF exits 1 for a non-conformant file, which is a
    // verdict rather than a failure to run. The verdict itself is the first
    // word of stdout either way.
    $output = app(LSNepomuceno\LaravelA1PdfSign\Support\ProcessRunner::class)->run(sprintf(
        'verapdf --format text -f %s %s 2>&1 || true',
        escapeshellarg($flavour),
        escapeshellarg($path),
    ));

    return str_starts_with(trim($output), 'PASS') ? 'PASS' : 'FAIL';
}

/**
 * A minimal PDF around the given objects, with a correct cross-reference table.
 *
 * Structural cases are built rather than patched into the committed fixture:
 * changing a dictionary's length shifts every offset after it, and the xref
 * would then point into the middle of objects. A test that reads the wrong
 * bytes can still pass, which is the worst kind.
 *
 * @param  array<int, string>  $objects  Bodies keyed by number, from 1 upwards.
 */
function pdfWith(array $objects): string
{
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objects as $number => $body) {
        $offsets[$number] = strlen($pdf);
        $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
    }

    $start = strlen($pdf);
    $size = count($objects) + 1;

    $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";

    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf . "trailer\n<</Size {$size}/Root 1 0 R>>\nstartxref\n{$start}\n%%EOF\n";
}
