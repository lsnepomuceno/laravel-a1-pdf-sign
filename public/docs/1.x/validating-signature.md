#### 1 - Validating signature
```PHP
<?php

use Illuminate\Http\Request;
use App\Models\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Sign\ValidatePdfSignature;

class ExampleController() {
    public function dummyFunction(Request $request){
        // Returning signed resource string
        try {
            dd(ValidatePdfSignature::from('path/to/pdf/file.pdf');
        } catch (\Throwable $th) {
            // TODO necessary
        }
    }
}

```
#### 2 - The expected result will be as shown below.
![signed fluent](https://user-images.githubusercontent.com/14093492/127238859-a02aec7b-8564-4e44-854b-4fde5de8e946.png)

#### 3 - The expected result in Adobe Acrobat/Reader will be as shown below.
![Signed File](https://user-images.githubusercontent.com/14093492/121451955-f2184c00-c974-11eb-90af-257fc814784f.png)

# For Brazilian users
#### Is possible to validate the signature through the government's ITI service.
#### Just access the address [https://verificador.iti.gov.br/](https://verificador.iti.gov.br/)
#### The expected result will be as shown below.
![icp-brasil-validation](https://user-images.githubusercontent.com/14093492/127506911-598042e0-91ee-4dc2-b487-e6aa01937d81.png)
