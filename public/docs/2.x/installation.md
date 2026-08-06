# Minimum requirements

* PHP: ^8.4 or ^8.5
* Laravel: ^13
* PHP Extensions: mbstring, dom, fileinfo, openssl, json, gd

Laravel 12 is **not** supported. It requires `symfony/process ^7.2` while the test stack requires `^8.1`, so the two cannot be installed together.

The `openssl` **binary** is no longer required. Certificates are read through `ext-openssl`; the command line is used only as a fallback for legacy PFX files (see [Working with certificates](/docs/2.x/working-with-certificate)).

# Install

```Shell
composer require lsnepomuceno/laravel-a1-pdf-sign
```

The service provider and the `A1PdfSign` facade are registered by package discovery — nothing to add to `config/app.php`.

# Configuration

Publishing the config file is optional; the defaults work as they are.

```Shell
php artisan vendor:publish --tag=a1-pdf-sign-config
```

```PHP
return [
    // Where short-lived files are written. Null uses the system temp dir.
    'temp_path' => env('A1_PDF_SIGN_TEMP_PATH'),

    'signature' => [
        // legacy | pades-b-b | pades-b-t | pades-b-lt | pades-b-lta
        'profile' => env('A1_PDF_SIGN_PROFILE', 'pades-b-b'),

        'digest_algorithm' => env('A1_PDF_SIGN_DIGEST', 'sha256'),

        // Required by pades-b-t and above.
        'timestamp' => [
            'url'      => env('A1_TSA_URL'),
            'username' => env('A1_TSA_USERNAME'),
            'password' => env('A1_TSA_PASSWORD'),
            'timeout'  => 20,
        ],

        'ltv' => ['timeout' => 10],
    ],

    'certificate' => [
        // Pass the host PATH to the openssl child process.
        'use_path_env' => env('A1_PDF_SIGN_USE_PATH_ENV', false),

        // openssl's -legacy flag, for old RC2/40-bit PFX files.
        'legacy' => env('A1_PDF_SIGN_LEGACY_CERTIFICATE', false),
    ],

    'seal' => [
        'driver' => env('A1_PDF_SIGN_IMAGE_DRIVER', 'gd'), // gd | imagick
        'font'   => ['path' => null, 'size' => 'large', 'color' => '#16A085'],
        'background' => null,
    ],
];
```

Every argument that has a configured default is nullable at the call site: passing `null` means "use the configuration", so a call site never has to repeat an infrastructure decision.
