
```PHP
<?php

use Illuminate\Http\Request;

class ExampleController() {
    public function dummyFunction(Request $request){
    	// SIGNATURE FROM FILE
        try {
            signPdfFromFile('path/to/certificate.pfx', 'password', 'path/to/pdf/file.pdf');
        } catch (\Throwable $th) {
            // TODO necessary
        }
	
	// SIGNATURE FROM UPLOAD
        try {
            signPdfFromUpload($request->pfxUploadedFile, $request->password, 'path/to/pdf/file.pdf');
        } catch (\Throwable $th) {
            // TODO necessary
        }
		
	// ENCRYPT CERTIFICATE DATA
	// path/to/certificate.pfx or uploaded certificate file
	// return object with attr`s [hash, certificate, password]
        try {
            $encriptedCertificate = encryptCertData('path/to/certificate.pfx', 'password');
        } catch (\Throwable $th) {
            // TODO necessary
        }
	
	// DECRYPT THE CERTIFICATE DATA
        try {
	    decryptCertData($encriptedCertificate->hash, $encriptedCertificate->certificate, $encriptedCertificate->password);
        } catch (\Throwable $th) {
            // TODO necessary
        }
	
	// VALIDATE PDF SIGNATURE
        try {
	    validatePdfSignature('path/to/pdf/file.pdf');
        } catch (\Throwable $th) {
            // TODO necessary
        }
    }
}

```
