# Sign PDF files with valid x509 certificate
[![Latest Stable Version](http://poser.pugx.org/lsnepomuceno/laravel-a1-pdf-sign/v)](https://packagist.org/packages/lsnepomuceno/laravel-a1-pdf-sign) 
[![Total Downloads](http://poser.pugx.org/lsnepomuceno/laravel-a1-pdf-sign/downloads)](https://packagist.org/packages/lsnepomuceno/laravel-a1-pdf-sign) 
[![Latest Unstable Version](http://poser.pugx.org/lsnepomuceno/laravel-a1-pdf-sign/v/unstable)](https://packagist.org/packages/lsnepomuceno/laravel-a1-pdf-sign) 
[![License](http://poser.pugx.org/lsnepomuceno/laravel-a1-pdf-sign/license)](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/blob/main/LICENSE.md)

# TL;DR
 -  [Install](https://github.com/lsnepomuceno/laravel-a1-pdf-sign#install)
 - [Usage](https://github.com/lsnepomuceno/laravel-a1-pdf-sign#usage)
    - [Working with certificate](https://github.com/lsnepomuceno/laravel-a1-pdf-sign#working-with-certificate)
	    - [Reading the certificate from file](https://github.com/lsnepomuceno/laravel-a1-pdf-sign#1---reading-the-certificate-from-file)
      - [Reading the certificate from upload](https://github.com/lsnepomuceno/laravel-a1-pdf-sign#2---reading-the-certificate-from-upload)
      - [The expected result](https://github.com/lsnepomuceno/laravel-a1-pdf-sign#3---the-expected-result-will-be-as-shown-below)
      - [Store certificate data securely in the database](https://github.com/lsnepomuceno/laravel-a1-pdf-sign#4---store-certificate-data-securely-in-the-database)
      - [Reading certificate from database](https://github.com/lsnepomuceno/laravel-a1-pdf-sign#5---reading-certificate-from-database)
    - [Sign PDF File](https://github.com/lsnepomuceno/laravel-a1-pdf-sign#sign-pdf-file)
       - [Sign PDF with certificate from file or upload](https://github.com/lsnepomuceno/laravel-a1-pdf-sign#1---sign-pdf-with-certificate-from-file-or-upload) 
       - [Sign PDF with certificate from database (model based)](https://github.com/lsnepomuceno/laravel-a1-pdf-sign#2---sign-pdf-with-certificate-from-database-model-based) 
       - [The expected result](https://github.com/lsnepomuceno/laravel-a1-pdf-sign#3---the-expected-result-in-adobe-acrobatreader-will-be-as-shown-below) 
  - [:collision: Is your project not Laravel/Lumen?](https://github.com/lsnepomuceno/laravel-a1-pdf-sign#collision-is-your-project-not-laravel--lumen)
    - [If you want to use this package in a project that is not based on Laravel / Lumen, you need to make the adjustments below](https://github.com/lsnepomuceno/laravel-a1-pdf-sign#if-you-want-to-use-this-package-in-a-project-that-is-not-based-on-laravel--lumen-you-need-to-make-the-adjustments-below)
       - [Install dependencies to work correctly](https://github.com/lsnepomuceno/laravel-a1-pdf-sign#1---install-dependencies-to-work-correctly) 
       - [Prepare the code to launch the Container and FileSystem instance](https://github.com/lsnepomuceno/laravel-a1-pdf-sign#2---prepare-the-code-to-launch-the-container-and-filesystem-instance) 
       - [After this parameterization, your project will work normally](https://github.com/lsnepomuceno/laravel-a1-pdf-sign#3---after-this-parameterization-your-project-will-work-normally) 
   - [Tests](https://github.com/lsnepomuceno/laravel-a1-pdf-sign#tests)

<hr />


# Install
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

<hr />

# :collision: Is your project not Laravel / Lumen?
### If you want to use this package in a project that is not based on Laravel / Lumen, you need to make the adjustments below
#### 1 - Install dependencies to work correctly. 
```Shell
composer require illuminate/container illuminate/filesystem ramsey/uuid
```

#### 2 - Prepare the code to launch the Container and FileSystem instance.
```PHP
<?php

require_once 'vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;

try {
  $app = new Container();
  $app->singleton('app', Container::class);
  $app->singleton('files', fn () => new Filesystem);
  Facade::setFacadeApplication($app);
  
  // Allow the use of Facades, only if necessary
  // $app->withFacades();
} catch (\Throwable $th) {
  // TODO necessary
}


```
#### 3 - After this parameterization, your project will work normally.
```PHP
<?php

require_once 'vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use LSNepomuceno\LaravelA1PdfSign\ManageCert;

try {
  $app = new Container();
  $app->singleton('app', Container::class);
  $app->singleton('files', fn () => new Filesystem);
  Facade::setFacadeApplication($app);
  
  $cert = new ManageCert;
  $cert->fromPfx(path/to/certificate.pfx', 'password');
  var_dump($cert->getCert());
} catch (\Throwable $th) {
  // TODO necessary
}


```


# Tests
#### Run the tests with:
```Shell
composer run-script test
```
or
```Shell
vendor/bin/phpunit
```
