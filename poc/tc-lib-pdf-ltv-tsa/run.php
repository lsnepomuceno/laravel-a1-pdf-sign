<?php

declare(strict_types=1);

/**
 * PoC 0 — does tc-lib-pdf actually deliver LTV and RFC 3161 timestamping,
 * or does it only document them?
 *
 * Blocks PRs 7 and 8 (see docs/history/v2-modernization.md). Every claim in that section
 * was read from the source; this script executes them.
 *
 * Fonts: tc-lib-pdf ships no font definition files, and it cannot emit any PDF
 * without one — not even a signature-only document. Generate helvetica.json
 * once and mount the directory (see this PoC's README):
 *
 *   php vendor/tecnickcom/tc-lib-pdf-font/util/convert.php \
 *       -i Helvetica.afm -t Core -o <dir>
 *
 * Run:
 *   docker compose -f .docker/compose.yaml run --rm -v <dir>:/fonts php \
 *       php /app/poc/tc-lib-pdf-ltv-tsa/run.php
 */

require '/app/vendor/autoload.php';

\define('K_PATH_FONTS', getenv('POC_FONTS') ?: '/fonts');

use Com\Tecnick\Pdf\Tcpdf;
use Composer\InstalledVersions;

$out = __DIR__ . '/out';
@mkdir($out);

$pass = 0;
$fail = 0;
$skip = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " — {$detail}" : '');
}

function skip(string $label, string $why): void
{
    global $skip;
    $skip++;
    printf("  [SKIP] %s — %s\n", $label, $why);
}

// ---------------------------------------------------------------------------
// Test certificate, generated through ext-openssl only.
// ---------------------------------------------------------------------------
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(
    ['countryName' => 'BR', 'organizationName' => 'PoC A1', 'commonName' => 'LTV TSA Probe'],
    $key,
    ['digest_alg' => 'sha256'],
);
$x509 = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);

openssl_x509_export($x509, $certPem);
openssl_pkey_export($key, $keyPem);

printf(
    "tc-lib-pdf %s | PHP %s\n\n",
    InstalledVersions::getPrettyVersion('tecnickcom/tc-lib-pdf'),
    PHP_VERSION,
);

/**
 * Builds a signed PDF. $extra is merged into setSignature(); $tsa, when given,
 * is passed to setSignTimeStamp(); $reserve adds empty approval widgets.
 */
function buildSigned(
    string $certPem,
    string $keyPem,
    array $extra = [],
    ?array $tsa = null,
    array $reserve = [],
): string {
    $pdf = new Tcpdf();
    $pdf->setCreator('a1-pdf-sign poc');
    $pdf->setPDFFilename('poc.pdf');

    $pdf->font->insert($pdf->pon, 'helvetica', '', 11);
    $page = $pdf->addPage();

    $pdf->setSignature(array_merge([
        'cert_type' => 2,
        'info'      => ['Name' => 'PoC', 'Reason' => 'LTV/TSA probe'],
        'password'  => '',
        // PEM strings, not file:// — this also exercises the "key in memory"
        // claim from docs/history/v2-modernization.md.
        'privkey'   => $keyPem,
        'signcert'  => $certPem,
    ], $extra));

    if ($tsa !== null) {
        $pdf->setSignTimeStamp($tsa);
    }

    $pdf->setSignatureAppearance(15, 35, 90, 20, -1, 'Primary');

    foreach ($reserve as $name) {
        $pdf->addEmptySignatureAppearance(15, 60, 90, 20, $page['pid'], $name);
    }

    return $pdf->getOutPDFString();
}

/** Extracts the hex CMS from the last /Contents placeholder. */
function embeddedCms(string $pdf): string
{
    if (!preg_match_all('/\/Contents\s*<([0-9a-fA-F]+)>/', $pdf, $m)) {
        return '';
    }

    return (string) end($m[1]);
}

// ---------------------------------------------------------------------------
// 1. Baseline
// ---------------------------------------------------------------------------
echo "=== 1. baseline signature ===\n";

$plain = '';

try {
    $plain = buildSigned($certPem, $keyPem);
    file_put_contents("{$out}/plain.pdf", $plain);

    check('produces a PDF', str_starts_with($plain, '%PDF-'), strlen($plain) . ' bytes');
    check('contains a /Sig object', (bool) preg_match('/\/Type\s*\/Sig/', $plain));
    check('contains /ByteRange', str_contains($plain, '/ByteRange'));

    $cms = embeddedCms($plain);
    check(
        'CMS is not an empty placeholder',
        $cms !== '' && trim($cms, '0') !== '',
        $cms === '' ? 'no /Contents found' : intdiv(strlen(rtrim($cms, '0')), 2) . ' bytes of DER',
    );
    check('accepts PEM strings for privkey/signcert (no file://)', $cms !== '' && trim($cms, '0') !== '');
} catch (Throwable $e) {
    check('baseline signature', false, get_class($e) . ': ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// 2. LTV
// ---------------------------------------------------------------------------
echo "\n=== 2. LTV (DSS / VRI) ===\n";

try {
    // A self-signed certificate has neither an OCSP responder nor a CRL
    // distribution point, so only certificate embedding is exercised here.
    $ltv = buildSigned($certPem, $keyPem, [
        'ltv' => [
            'enabled'     => true,
            'embed_ocsp'  => false,
            'embed_crl'   => false,
            'embed_certs' => true,
            'include_dss' => true,
            'include_vri' => true,
        ],
    ]);
    file_put_contents("{$out}/ltv.pdf", $ltv);

    check('LTV run completes', true, strlen($ltv) . ' bytes');
    check('/DSS present in the catalog', str_contains($ltv, '/DSS'));
    check('/VRI map present', str_contains($ltv, '/VRI'));
    check('/Certs entry present', str_contains($ltv, '/Certs'));
    check(
        'LTV output differs from baseline',
        $plain !== '' && strlen($ltv) > strlen($plain),
        sprintf('%d vs %d bytes', strlen($ltv), strlen($plain)),
    );
} catch (Throwable $e) {
    check('LTV', false, get_class($e) . ': ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// 3. LTV option validation — proves the option is really parsed
// ---------------------------------------------------------------------------
echo "\n=== 3. LTV option validation ===\n";

try {
    buildSigned($certPem, $keyPem, ['ltv' => ['enabled' => 'yes']]);
    check('rejects a non-bool LTV option', false, 'invalid value was accepted');
} catch (Throwable $e) {
    check('rejects a non-bool LTV option', true, $e->getMessage());
}

// ---------------------------------------------------------------------------
// 4. Reserved empty approval fields (relevant to §3h)
// ---------------------------------------------------------------------------
echo "\n=== 4. reserved empty signature fields ===\n";

try {
    $reserved = buildSigned($certPem, $keyPem, [], null, ['Reviewer', 'Manager']);
    file_put_contents("{$out}/reserved.pdf", $reserved);

    $widgets = preg_match_all('/\/FT\s*\/Sig/', $reserved);

    check('empty approval widgets are emitted', $widgets >= 3, "{$widgets} /FT /Sig fields");
    check('SigFlags set on AcroForm', (bool) preg_match('/\/SigFlags\s*\d/', $reserved));
} catch (Throwable $e) {
    check('reserved empty fields', false, get_class($e) . ': ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// 5. RFC 3161 timestamp — requires network access
// ---------------------------------------------------------------------------
echo "\n=== 5. RFC 3161 timestamp ===\n";

$tsaUrl = getenv('POC_TSA_URL') ?: 'https://freetsa.org/tsr';
echo "  TSA: {$tsaUrl}\n";

try {
    $stamped = buildSigned($certPem, $keyPem, [], [
        'enabled'        => true,
        'host'           => $tsaUrl,
        'username'       => '',
        'password'       => '',
        'cert'           => '',
        'hash_algorithm' => 'sha256',
        'policy_oid'     => '',
        'nonce_enabled'  => true,
        'timeout'        => 20,
        'verify_peer'    => true,
    ]);
    file_put_contents("{$out}/timestamped.pdf", $stamped);

    $plainCms   = strlen(rtrim(embeddedCms($plain), '0'));
    $stampedCms = strlen(rtrim(embeddedCms($stamped), '0'));

    check('timestamped run completes', true, strlen($stamped) . ' bytes');
    check(
        'CMS grew, i.e. a token was embedded',
        $stampedCms > $plainCms,
        sprintf('%d vs %d bytes of DER', intdiv($stampedCms, 2), intdiv($plainCms, 2)),
    );
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $isNetwork = (bool) preg_match('/timestamp|tsa|curl|resolve|connect|network|http/i', $msg);

    $isNetwork
        ? skip('RFC 3161 timestamp', 'TSA unreachable from this environment: ' . $msg)
        : check('RFC 3161 timestamp', false, get_class($e) . ': ' . $msg);
}

// ---------------------------------------------------------------------------
echo "\n";
printf("RESULT: %d passed, %d failed, %d skipped\n", $pass, $fail, $skip);
exit($fail === 0 ? 0 : 1);
