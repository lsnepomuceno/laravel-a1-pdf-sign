#### Signing a document:
##### Command signature:
```Shell
pdf:sign
       {pdfPath : The path to the PDF file}
       {certificatePath : The path to the certificate, PKCS#12 or PEM}
       {password : The certificate password, empty for an unencrypted PEM key}
       {fileName? : The signed file name}
       {--key= : The PEM private key, when it lives in its own file}
```

##### Example:
```Shell
php artisan pdf:sign '/example/full/path/to/file.pdf' '/example/full/path/to/certificate.pfx' 'password123' 'MySignedFileName'
```

Omitting the file name writes to a generated path in the configured temporary directory, and the command prints where the file landed. The signature uses the profile configured in `a1-pdf-sign.signature.profile`.

##### PEM certificates (since 2.1):
The encoding is read from the file's content, not from its extension, so a PEM certificate is passed to the same argument:

```Shell
php artisan pdf:sign '/path/to/file.pdf' '/path/to/certificate.pem' 'password123'
```

When the private key lives in a file of its own, point `--key` at it. Pass an empty password if that key is unencrypted:

```Shell
php artisan pdf:sign '/path/to/file.pdf' '/path/to/certificate.crt' '' --key='/path/to/private.key'
```

Passing `--key` together with a PKCS#12 bundle fails rather than being ignored: the bundle already carries its key, so the combination means the caller is mistaken about what they hold.

> The second argument was named `pfxPath` before 2.1. Only `Artisan::call()` with named keys is affected; on the command line the arguments are positional.

<hr />

#### Validating a signed document:
##### Command signature:
```Shell
pdf:validate-signature
                    {pdfPath : The path to the PDF file}
```

##### Example:
```Shell
php artisan pdf:validate-signature '/example/full/path/to/my/signed-file.pdf'
```

It reports every signature in the document, the signer's common name and whether each one verified, not only the first. Documents carrying several signatures are listed in full.

<hr />

#### Checking the environment: <small>(since 2.6)</small>
##### Command signature:
```Shell
a1-pdf-sign:check
                {--tsa : Also reach the configured timestamp authority}
```

##### Example:
```Shell
php artisan a1-pdf-sign:check
```

```
  [ok] ext-openssl            PKCS#12 reading and CMS building
  [ok] ext-bcmath             required by tc-lib-pdf through tc-lib-barcode
  [ok] proc_open              validation shells out; often in disable_functions
  [ok] openssl binary         validation and legacy PFX. Separate from ext-openssl
  [ok] ext-gd or ext-imagick  only needed to draw a visible seal
  [ok] temporary directory    every shell-out writes one
  [ok] memory_limit           signing peaks at roughly 20 MB plus twice the document

This environment can sign and validate.
```

**`ext-openssl` being loaded says nothing about the `openssl` binary being installed.** They are separate things, a minimal container commonly has the first without the second, and validation needs the second. Until 2.6 a host missing it reported every signature as invalid rather than saying so.

It exits non-zero only for what makes signing or validation impossible, so a deployment pipeline can use it. A host with no image library is reported and not failed: that only matters if you draw a visible seal.

**It does not touch the network unless asked.** `--tsa` opts in, and goes through the same transport contract everything else does.

Nothing sensitive is printed, so the output is safe to paste into an issue.

<hr />

All three commands map a `Throwable` to a failure exit code, so they compose in a shell pipeline.
