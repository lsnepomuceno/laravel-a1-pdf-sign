# Minimum requirements

* PHP: ^8.4 or ^8.5
* Laravel: ^13
* PHP Extensions: mbstring, dom, fileinfo, openssl, json, bcmath, and gd or imagick

Laravel 12 is **not** supported. It requires `symfony/process ^7.2` while the test stack requires `^8.1`, so the two cannot be installed together.

`gd` or `imagick` is needed only to draw a visible seal. An invisible signature needs neither.

### The `openssl` binary

**Signing does not need it.** Certificates are read through `ext-openssl`, and the command line is a fallback for legacy PFX files only (see [Working with certificates](/docs/2.x/working-with-certificate)).

**Validation does.** The CMS verdict comes from the binary, and `proc_open` has to be available to reach it, which much shared hosting disables.

> `ext-openssl` being loaded says nothing about the binary being installed. They are separate things, and a minimal container commonly has the first without the second. Before 2.6 a host missing it reported every signature as **invalid** rather than saying so; it now raises `MissingBinaryException`.

```Shell
php artisan a1-pdf-sign:check
```

reports all of the above and exits non-zero when something makes signing or validation impossible, so a deployment pipeline can use it.

# Install

```Shell
composer require lsnepomuceno/laravel-a1-pdf-sign
```

The service provider and the `A1PdfSign` facade are registered by package discovery, so nothing to add to `config/app.php`.

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

        // since 2.4
        'transparent' => true,                    // honour the artwork's alpha channel
        'text' => ['x' => 160, 'rows' => [80, 150, 250]],
    ],
];
```

`transparent` defaults to **`true` since 2.4**, where a seal was previously flattened onto white. It costs bytes, since the alpha travels as a separate `/SMask` image rather than inside a JPEG, and it makes PDF/A-1 impossible: §6.4 forbids `/SMask`. Set it to `false` for the old opaque rectangle.

Every argument that has a configured default is nullable at the call site: passing `null` means "use the configuration", so a call site never has to repeat an infrastructure decision.
