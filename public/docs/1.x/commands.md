#### Signing a new document:
##### Command signature:
```Shell
pdf:sign
       {pdfPath : The path to the PDF file}
       {pfxPath : The path to the certificate file}
       {password : The certificate password}
       {fileName? : The signed file name}
```

##### Example:
```PHP
php artisan pdf:sign '/example/full/path/to/file.pdf' '/example/full/path/to/certificate.pfx' 'password123' 'MySignedFileName'
```
<hr />

#### Validating a signed document:
##### Command signature:
```Shell
pdf:validate-signature
                    {pdfPath : The path to the PDF file}
```

##### Example:
```PHP
php artisan pdf:validate-signature '/example/full/path/to/my/signed-file.pdf'
```
