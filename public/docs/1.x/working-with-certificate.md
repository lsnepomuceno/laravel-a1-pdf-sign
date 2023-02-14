#### 1 - Reading the certificate from file.
```PHP
<?php

use LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert;

class ExampleController() {
    public function dummyFunction(){
        try {
            $cert = new ManageCert;
            $cert->setPreservePfx() // If you need to preserve the PFX certificate file
                 ->fromPfx('path/to/certificate.pfx', 'password');
            dd($cert->getCert());
        } catch (\Throwable $th) {
            // TODO necessary
        }
    }
}

```

#### 2 - Reading the certificate from upload.
```PHP
<?php

use Illuminate\Http\Request;
use LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert;

class ExampleController() {
    public function dummyFunction(Request $request){
        try {
            $cert = new ManageCert;
            $cert->fromUpload($request->pfxUploadedFile, $request->password);
            dd($cert->getCert());
        } catch (\Throwable $th) {
            // TODO necessary
        }
    }
}


```

#### 3 - The expected result will be as shown below.
![Certificate](https://user-images.githubusercontent.com/14093492/121448900-fe99a600-c96e-11eb-9d39-7798a5987ebb.png)


#### 4 - Store certificate data securely in the database.
##### IMPORTANT: Store certificate columns as binary data type
```PHP
<?php

use App\Models\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert;

class ExampleController() {
    public function dummyFunction(){
        try {
            $cert = new ManageCert;
            $cert->fromPfx('path/to/certificate.pfx', 'password');
        } catch (\Throwable $th) {
            // TODO necessary
        }
                
        // Postgres or MS SQL Server
        Certificate::create([
          'certificate' => $cert->getEncrypter()->encryptString($cert->getCert()->original) 
          'password'    => $cert->getEncrypter()->encryptString('password'),
          'hash'        => $cert->getHashKey(), // IMPORTANT
          ...
        ]);
        
        // For MySQL
        Certificate::create([
          'certificate' => $cert->encryptBase64BlobString($cert->getCert()->original) 
          'password'    => $cert->getEncrypter()->encryptString('password'),
          'hash'        => $cert->getHashKey(), // IMPORTANT
          ...
        ]);
    }
}


```

#### 5 - Reading certificate from database (model based).
```PHP
<?php
use LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert;

class CertificateModel() {
    public function parse(): ?ManageCert {
        try {
            // IMPORTANT
            // Set true if you only use the "encryptBase64BlobString" method
	    return decryptCertData($this->hash, $this->certificate, $this->password, true); 
        } catch (\Throwable $th) {
            // TODO necessary
        }
    }
}

```
