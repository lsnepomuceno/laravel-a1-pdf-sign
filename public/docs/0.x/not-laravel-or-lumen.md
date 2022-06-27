# IMPORTANT
Because the project is not Laravel/Lumen, the use of **upload, download** and **encryption** features is not documented.

Find functionality by implementing the framework's **service containers** or create your own solution.
***

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
  $app->singleton('files', Filesystem::class);
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
  $cert->fromPfx('path/to/certificate.pfx', 'password');

  $pdf = new SignaturePdf('path/to/pdf/file.pdf', $cert, SignaturePdf::MODE_RESOURCE) // Use resource mode is default
  $resource = $pdf->signature();
} catch (\Throwable $th) {
  // TODO necessary
}

```
