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

Both commands map a `Throwable` to a failure exit code, so they compose in a shell pipeline.
