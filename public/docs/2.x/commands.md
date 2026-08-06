#### Signing a document:
##### Command signature:
```Shell
pdf:sign
       {pdfPath : The path to the PDF file}
       {pfxPath : The path to the certificate file}
       {password : The certificate password}
       {fileName? : The signed file name}
```

##### Example:
```Shell
php artisan pdf:sign '/example/full/path/to/file.pdf' '/example/full/path/to/certificate.pfx' 'password123' 'MySignedFileName'
```

Omitting the file name writes to a generated path in the configured temporary directory, and the command prints where the file landed. The signature uses the profile configured in `a1-pdf-sign.signature.profile`.

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

It reports every signature in the document — the signer's common name and whether each one verified — not only the first. Documents carrying several signatures are listed in full.

Both commands map a `Throwable` to a failure exit code, so they compose in a shell pipeline.
