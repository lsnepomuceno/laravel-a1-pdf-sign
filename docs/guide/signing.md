# Signing

The builder is the primary way in, and it is signet-pdf's `PendingSignature`
reached through this package's facade:

```php
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

$signed = A1PdfSign::newSignature()
    ->certificate($pfxPath, $password)
    ->pdf($contractPath)
    ->info(name: 'Lucas Nepomuceno', reason: 'Contract approval')
    ->profile(SignatureProfile::PadesBLT)
    ->seal()
    ->sign();
```

Everything it accepts, and what each option does to the bytes, is
[signet-pdf's signing reference](https://github.com/lsnepomuceno/signet-pdf/blob/main/docs/guide/signing.md).
What follows is what is specific to using it inside Laravel.

## A signature appends, it never rebuilds

The document you signed survives byte for byte, and a second signature does not
invalidate the first. That is the single most important behaviour in the
engine, and it is why signing the same document twice is a supported thing to
do rather than a corruption waiting to happen.

## Anything configured can be left unsaid

Every argument that names an infrastructure decision is nullable, and null
means "use the configured default":

```php
A1PdfSign::signFromFile($pfx, $password, $pdf);            // profile from config
A1PdfSign::signFromFile($pfx, $password, $pdf, usePathEnv: true);   // overridden here
```

So a call site says what is specific to the call and nothing else. The
[configuration page](/guide/configuration) is where the rest lives.

## One-shot helpers

```php
A1PdfSign::signFromFile($pfxPath, $password, $pdfPath);
A1PdfSign::signFromPem($pemPath, $password, $pdfPath, $keyPath);
A1PdfSign::signFromUpload($request->file('certificate'), $password, $pdfPath);
```

Shortcuts for the case with no seal, no profile override and no certification.
Anything beyond that wants the builder.

## What comes back

`Data\SignedPdf`, which is bytes plus a name:

```php
$signed->contents;                      // the document
$signed->save($path);                   // to a local path
$signed->writeTo(A1PdfSign::toDisk('s3'));  // to a disk
$signed->receipt();                     // what was signed, and what it grew by
```

`receipt()` is a method rather than a property because it hashes the document.
It answers the question a caller would otherwise reparse the file for: which
profile this was, what was embedded, and what was skipped.

## Signing where the key is somewhere else

For a private key in an HSM, a remote service or a smartcard, signing splits in
two and **the key never enters the process**:

```php
$prepared = A1PdfSign::newSignature()
    ->certificatePublic($certificatePem)
    ->pdf($contract)
    ->prepare();

$cms = $yourHsm->sign($prepared->digestValue);

$signed = A1PdfSign::complete($prepared, $cms);
```

`$prepared` carries no key and no secret, so it survives a queue. That is
usually the point: the digest goes to a worker with access to the signing
device, and the document comes back finished.

## In a queued job

Nothing here holds a connection or a handle, so a signature is a job like any
other. Two things are worth knowing:

- `A1PdfSign::tempPath()` creates the configured directory if it is not there,
  which a fresh container will need.
- Signing holds the document in memory. For one too large for a worker's
  memory limit, the engine takes a stream.
