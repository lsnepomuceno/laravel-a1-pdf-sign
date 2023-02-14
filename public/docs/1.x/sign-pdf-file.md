#### 1 - Sign PDF with certificate from file or upload.
```PHP
<?php

use Illuminate\Http\Request;
use LSNepomuceno\LaravelA1PdfSign\Sign\{ManageCert, SignaturePdf};

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
            $pdf = new SignaturePdf('path/to/pdf/file.pdf', $cert, SignaturePdf::MODE_RESOURCE) // Resource mode is default
            $resource = $pdf->signature();
            // TODO necessary
        } catch (\Throwable $th) {
            // TODO necessary
        }

        // It is possible to pass a file directly through the upload
        try {
            $pdf = new SignaturePdf($request->file('PDFfile'), $cert, SignaturePdf::MODE_RESOURCE) // Resource mode is default
            $resource = $pdf->signature();
            // TODO necessary
        } catch (\Throwable $th) {
            // TODO necessary
        }
        
        // Downloading signed file
        try {
            $pdf = new SignaturePdf('path/to/pdf/file.pdf', $cert, SignaturePdf::MODE_DOWNLOAD)
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
use LSNepomuceno\LaravelA1PdfSign\Sign\{ManageCert, SignaturePdf};

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
#### 3 - Sign PDF with image insertion
```PHP
<?php

use Illuminate\Http\Request;
use App\Models\Certificate;
use Illuminate\Support\Facades\File;
use LSNepomuceno\LaravelA1PdfSign\Sign\{ManageCert, SealImage, SignaturePdf};

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
        
        // GENERATING IMAGE FROM CERTIFICATE
        $image = SealImage::fromCert($cert);

        // IMAGE STORAGE LOCATION
        $imagePath = a1TempDir(true, '.png');
        File::put($imagePath, $image);

        // Returning signed resource string
        try {
            $pdf = new SignaturePdf('path/to/pdf/file.pdf', $cert)
            $resource = $pdf->setImage($imagePath) // USE THE "setImage" METHOD
                            ->signature();
            // TODO necessary
        } catch (\Throwable $th) {
            // TODO necessary
        }
    }
}

```

#### 3.1 - The "setImage" method
```PHP
<?php

[...]

public function setImage(
    string $imagePath, // Image path location
    float  $pageX = 155, // X page position
    float  $pageY = 250, // Y page position
    float  $imageW = 50, // The image width, if set to 0, the original or proportional image size will be used
    float  $imageH = 0   // The image height, if set to 0, the original or proportional image size will be used
): SignaturePdf

[...]

```

#### 3.2 - The expected result will be as shown below
## IMPORTANT
### All information displayed on the signature seal is taken from the certificate.
![demonstration seal](https://user-images.githubusercontent.com/14093492/128951118-05e5eb6c-1dec-4d05-b6ab-059fdce30934.png)

#### 3.3 - In some cases you may need to create your own signature seal, check the implementation of the [SealImage::fromCert](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/blame/679eb2d52b7058bb9a2b1e68ed2ab7a0df6e0673/src/SealImage.php#L59) method for details.

#### 3.4 - Extras
```PHP
<?php
[...]
try {
    $pdf = new SignaturePdf('path/to/pdf/file.pdf', $cert)
    $resource = $pdf->setImage($imagePath) // Defines an image in place of the document's signature.
                    ->setFileName('new-file-name.pdf') // Use the "setFileName" method if you want to modify the name of the file that will be returned.
                    ->setInfo( // Defines extra information for the digital signature.
                         $name,
                         $location,
                         $reason,
                         $contactInfo 
                    )
                    ->setHasSignedSuffix(false) // By default the suffix "_signed" will be included at the end of the filename, if you don't want this behavior, just set the value "false" in the "setHasSignedSuffix" method.
                    ->signature();
    // TODO necessary
} catch (\Throwable $th) {
   // TODO necessary
}
[...]
```
