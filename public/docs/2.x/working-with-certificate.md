#### 1 - Reading a certificate from a file.

Most of the time you do not read the certificate yourself — the builder does it. Reading it directly is useful when you need the parsed data.

```PHP
<?php

use LSNepomuceno\LaravelA1PdfSign\Contracts\CertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;

class ExampleController
{
    public function dummyFunction(CertificateReader $reader)
    {
        $certificate = $reader->read(Files::read('path/to/certificate.pfx'), 'password');

        $certificate->commonName();   // 'Lucas Nepomuceno'
        $certificate->expiresAt();    // unix timestamp
        $certificate->original;       // the PEM bundle
    }
}
```

The certificate is never written to disk, and the password never reaches a command line. Both were true in 1.x and were the two most serious problems in it.

<hr>

#### 2 - Reading a PEM certificate. <small>(since 2.1)</small>

PKCS#12 (`.pfx` / `.p12`) is not the only encoding accepted. A PEM certificate is read through its own entry point, with the private key in the same file or in one of its own.

```PHP
<?php

use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

class ExampleController
{
    public function dummyFunction()
    {
        // Certificate and key combined in one file
        $signed = A1PdfSign::newSignature()
            ->certificatePem('path/to/certificate.pem', password: 'password')
            ->pdf('path/to/document.pdf')
            ->sign();

        // Key in a file of its own
        $signed = A1PdfSign::newSignature()
            ->certificatePem('path/to/certificate.crt', 'path/to/private.key', 'password')
            ->pdf('path/to/document.pdf')
            ->sign();

        // From bytes you already hold — an upload, a secret manager, a database column
        $signed = A1PdfSign::newSignature()
            ->certificateFromPem($certificateBytes, $keyBytes)
            ->pdf('path/to/document.pdf')
            ->sign();
    }
}
```

**Nothing gates on the file extension.** PEM ships as `.pem`, `.crt`, `.cer`, `.key` and `.txt`, so the encoding is decided by the content — a certificate under any of those suffixes is read the same way.

The password defaults to empty, because **a PEM private key is frequently unencrypted, which PKCS#12 cannot express**. OpenSSL ignores a passphrase given for a key that does not need one, so passing it either way is safe. Prefer an encrypted key where you have the choice: an unprotected one is readable by anything that can read the file.

Content that is not PEM at all — binary DER, or a PKCS#12 bundle handed to this entry point — raises `InvalidPemContentException`, naming the half at fault instead of reporting a generic parse failure. A certificate and key that are each valid but unrelated raise `InvalidX509PrivateKeyException`.

<hr>

#### 3 - Reading a certificate from an upload.

```PHP
<?php

use Illuminate\Http\Request;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

class ExampleController
{
    public function dummyFunction(Request $request)
    {
        $signed = A1PdfSign::newSignature()
            ->certificateFromUpload($request->file('certificate'), $request->input('password'))
            ->pdf('path/to/document.pdf')
            ->sign();
    }
}
```

<hr>

#### 4 - Storing a certificate securely in the database.

##### IMPORTANT: store the certificate column as a binary type.

```PHP
<?php

use App\Models\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

class ExampleController
{
    public function dummyFunction()
    {
        $encrypted = A1PdfSign::encryptCertificate('path/to/certificate.pfx', 'password');

        Certificate::create([
            'certificate' => $encrypted->encryptedData,
            'password'    => $encrypted->encryptedPassword,
            'hash'        => $encrypted->hashKey, // IMPORTANT: without it the pair cannot be read back
        ]);
    }
}
```

`encryptCertificate()` also accepts an `UploadedFile` directly, and — since 2.1 — a PEM certificate. It gained no PEM sibling: it takes "a certificate" generically and detects the encoding, where signing keeps explicit entry points so the caller states what it holds.

<hr>

#### 5 - Reading the certificate back.

```PHP
<?php

use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

class CertificateModel
{
    public function parse(): Certificate
    {
        return A1PdfSign::decryptCertificate(
            hashKey: $this->hash,
            encryptedCertificate: $this->certificate,
            password: $this->password,
            isBase64: false, // true only if the column stored a base64 blob
        );
    }
}
```

Then hand it to the builder:

```PHP
A1PdfSign::newSignature()
    ->usingCertificate($model->parse())
    ->pdf('path/to/document.pdf')
    ->sign();
```

> **This round trip did not work in 1.x.** `encryptCertData()` stored the PEM bundle and `decryptCertData()` fed it to `openssl pkcs12 -in`, which expects binary PKCS#12 and always failed. It went unnoticed because the old suite only ever wrote, and never read back.

<hr>

#### 6 - Legacy certificates.

Under OpenSSL 3.x, `openssl_pkcs12_read()` fails on old PFX files (RC2/40-bit) and PHP exposes no equivalent to the CLI's `-legacy` flag. For those files only, the package falls back to the `openssl` binary:

```PHP
// config/a1-pdf-sign.php
'certificate' => [
    'legacy' => true,

    // Only if the binary is not on the default search path.
    'use_path_env' => true,
],
```

This is the one place the package still spawns a process. Everything else is native.
