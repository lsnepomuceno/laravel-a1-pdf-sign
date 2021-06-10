# Sign PDF files with valid x509 certificate
Require this package in your composer.json and update composer. This will download the package and the dependencies libraries also.

```Shell
composer require lsnepomuceno/laravel-a1-pdf-sign
```

# Usage
## Working with certificate
#### 1 - Reading the certificate from file.
```PHP
<?php

use LSNepomuceno\LaravelA1PdfSign\ManageCert;

class ExampleController() {
    public function dummyFunction(){
        try {
            $cert = new ManageCert;
            $cert->fromPfx('path/to/certificate.pfx', 'password');
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
use LSNepomuceno\LaravelA1PdfSign\ManageCert;

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
##### IMPORTANT: Store certificate column as binary data type
```PHP
<?php

use App\Models\Certificate;
use LSNepomuceno\LaravelA1PdfSign\ManageCert;

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

#### 5 - Reading certificate from database.
```PHP
<?php

use LSNepomuceno\LaravelA1PdfSign\ManageCert;
use Illuminate\Support\{Str, Facades\File};

class CertificateModel() {
    public function parse() {
        $cert = new ManageCert;
        $cert->setHashKey($this->hash);
        $pfxName = $cert->getTempDir() . Str::orderedUuid() . '.pfx';

        // Postgres or MS SQL Server
        File::put($pfxName, $cert->getEncrypter()->decryptString($this->bb_cert));
        
        // For MySQL
        File::put($pfxName, $cert->decryptBase64BlobString($this->bb_cert));
        
        try {
          return $cert->fromPfx(
              $pfxName,
              $cert->getEncrypter()->decryptString($this->password)
          );
        } catch (\Throwable $th) {
            // TODO necessary
        }
    }
}

```

## Sign PDF File
#### 1 - Sign PDF with certificate from file or upload.
```PHP
<?php

use Illuminate\Http\Request;
use LSNepomuceno\LaravelA1PdfSign\{ManageCert, SignaturePdf};

class ExampleController() {
    public function dummyFunction(Request $request){
        
        // FROM FILE
        try {
            $cert = new ManageCert;
            $cert->fromPfx('path/to/certificate.pfx', 'password');
        } catch (\Throwable $th) {
            // TODO necessary
        }
        
        // FROM UPLOAD
        try {
            $cert = new ManageCert;
            $cert->fromUpload($request->pfxUploadedFile, $request->password);
            dd($cert->getCert());
        } catch (\Throwable $th) {
            // TODO necessary
        }
        
        // Returning signed resource string
        try {
            $pdf = new SignaturePdf('path/to/pdf/file.pdf', $cert->getCert(), SignaturePdf::MODE_RESOURCE) // Resource mode is default
            $resource = $pdf->signature();
            // TODO necessary
        } catch (\Throwable $th) {
            // TODO necessary
        }
        
        // Downloading signed file
        try {
            $pdf = new SignaturePdf('path/to/pdf/file.pdf', $cert->getCert(), SignaturePdf::MODE_DOWNLOAD)
            return $pdf->signature(); // The file will be downloaded
        } catch (\Throwable $th) {
            // TODO necessary
        }
    }
}

```

#### 2 - Sign PDF with certificate from database (model based).
```PHP
<?php

use Illuminate\Http\Request;
use App\Models\Certificate;
use LSNepomuceno\LaravelA1PdfSign\{ManageCert, SignaturePdf};

class ExampleController() {
    public function dummyFunction(Request $request){
        
        // Find certificate
        $cert = Certificate::find(1);
        
        // Returning signed resource string
        try {
            $pdf = new SignaturePdf('path/to/pdf/file.pdf', $cert->parse(), SignaturePdf::MODE_RESOURCE) // Resource mode is default
            $resource = $pdf->signature();
            // TODO necessary
        } catch (\Throwable $th) {
            // TODO necessary
        }
        
        // Downloading signed file
        try {
            $pdf = new SignaturePdf('path/to/pdf/file.pdf', $cert->parse(), SignaturePdf::MODE_DOWNLOAD)
            return $pdf->signature(); // The file will be downloaded
        } catch (\Throwable $th) {
            // TODO necessary
        }
    }
}

```

#### 3 - The expected result in Adobe Acrobat/Reader will be as shown below.
![Signed File](https://user-images.githubusercontent.com/14093492/121451955-f2184c00-c974-11eb-90af-257fc814784f.png)
