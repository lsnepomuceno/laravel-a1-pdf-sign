<?php

declare(strict_types=1);

/**
 * PoC 0b driver: signs the same PDF three times through incremental
 * revisions and checks that all three signatures survive.
 */

require __DIR__ . '/IncrementalSigner.php';

/**
 * Truncates the DER blob at the length declared in its ASN.1 header,
 * discarding the zero padding of the /Contents placeholder.
 */
function derTruncate(string $bin): string
{
    $lenByte = ord($bin[1]);

    if ($lenByte < 0x80) {
        return substr($bin, 0, 2 + $lenByte);
    }

    $count = $lenByte & 0x7F;
    $len   = 0;

    for ($i = 0; $i < $count; $i++) {
        $len = ($len << 8) | ord($bin[2 + $i]);
    }

    return substr($bin, 0, 2 + $count + $len);
}

$src = getenv('POC_PDF') ?: __DIR__ . '/../../tests/Resources/test.pdf';
$out = __DIR__ . '/out';
@mkdir($out);

// --- test certificate, entirely through ext-openssl (no CLI) ---------------
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($key === false) {
    exit('failed to generate key: ' . openssl_error_string() . "\n");
}

$dn   = ['countryName' => 'BR', 'organizationName' => 'PoC A1', 'commonName' => 'Incremental Test'];
$csr  = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
$x509 = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);

openssl_x509_export($x509, $certPem);
openssl_pkey_export($key, $keyPem);

echo "test certificate generated (ext-openssl, no shell-out)\n\n";

// --- sign three times ------------------------------------------------------
$signer  = new IncrementalSigner();
$pdf     = (string) file_get_contents($src);
$origLen = strlen($pdf);

echo "original: {$origLen} bytes\n\n";

for ($i = 1; $i <= 3; $i++) {
    $before = strlen($pdf);
    $pdf    = $signer->sign($pdf, $certPem, $keyPem, [
        'Name'   => "Signer {$i}",
        'Reason' => "Signature number {$i}",
    ]);
    file_put_contents("{$out}/signed-{$i}.pdf", $pdf);

    printf(
        "signature %d: %d -> %d bytes (+%d)  | original prefix intact: %s\n",
        $i,
        $before,
        strlen($pdf),
        strlen($pdf) - $before,
        substr($pdf, 0, $origLen) === (string) file_get_contents($src) ? 'YES' : 'NO',
    );
}

// --- structural check ------------------------------------------------------
echo "\n=== structure of the final file ===\n";

preg_match_all('/\/ByteRange\[0 (\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdf, $br, PREG_SET_ORDER);
preg_match_all('/startxref\s+(\d+)\s*%%EOF/', $pdf, $sx);
preg_match_all('/\/Prev\s+(\d+)/', $pdf, $pv);
preg_match_all('/\/Type\s*\/Sig[^n]/', $pdf, $sg);

printf("  /Sig objects ........ %d\n", count($sg[0]));
printf("  byte ranges ......... %d\n", count($br));
printf("  startxref ........... %d  -> %s\n", count($sx[1]), implode(', ', $sx[1]));
printf("  /Prev chain ......... %d  -> %s\n", count($pv[1]), implode(', ', $pv[1]));

// --- cryptographic verification -------------------------------------------
echo "\n=== cryptographic verification of each signature ===\n";

$tmp      = sys_get_temp_dir();
$ok       = 0;
$certFile = "{$tmp}/poc_cert.pem";
file_put_contents($certFile, $certPem);

foreach ($br as $n => $m) {
    [$a, $b, $c] = [(int) $m[1], (int) $m[2], (int) $m[3]];

    // Content covered by THIS signature.
    $signed = substr($pdf, 0, $a) . substr($pdf, $b, $c);

    // Embedded CMS, between '<' and '>'. The placeholder is zero-padded on the
    // right, so the real end comes from the ASN.1 header length, not from
    // rtrim(), which would cut legitimate 0x00 bytes of the DER itself.
    $hex = substr($pdf, $a + 1, $b - $a - 2);
    $der = derTruncate((string) hex2bin($hex));

    $dataFile = "{$tmp}/poc_data_{$n}";
    $sigFile  = "{$tmp}/poc_sig_{$n}.der";
    file_put_contents($dataFile, $signed);
    file_put_contents($sigFile, $der);

    exec(
        sprintf(
            'openssl smime -verify -binary -inform DER -in %s -content %s '
            . '-certfile %s -CAfile %s -purpose any -out /dev/null 2>&1',
            escapeshellarg($sigFile),
            escapeshellarg($dataFile),
            escapeshellarg($certFile),
            escapeshellarg($certFile),
        ),
        $outLines,
        $code,
    );

    $code === 0 && $ok++;

    printf(
        "  signature %d: ByteRange[0 %d %d %d] covers %d of %d bytes -> %s\n",
        $n + 1,
        $a,
        $b,
        $c,
        $a + $c,
        strlen($pdf),
        $code === 0 ? 'VALID' : 'FAILED',
    );

    if ($code !== 0) {
        echo '      ' . implode("\n      ", array_slice($outLines, 0, 3)) . "\n";
    }
    $outLines = [];

    @unlink($dataFile);
    @unlink($sigFile);
}

@unlink($certFile);

echo "\n";
echo $ok === count($br) && $ok === 3
    ? "RESULT: {$ok}/3 signatures valid: multiple signatures CONFIRMED\n"
    : 'RESULT: only ' . $ok . '/' . count($br) . " valid\n";
