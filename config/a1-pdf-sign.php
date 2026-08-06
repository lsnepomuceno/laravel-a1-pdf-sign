<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Temporary files
    |--------------------------------------------------------------------------
    |
    | Where the package writes the short-lived files it needs while converting
    | certificates and producing signed documents. Leave null to use the
    | system temporary directory.
    |
    | Writing inside the package directory is no longer the default: it
    | required vendor/ to be writable and behaved differently per environment.
    |
    */

    'temp_path' => env('A1_PDF_SIGN_TEMP_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Certificate reading
    |--------------------------------------------------------------------------
    |
    | use_path_env  Pass the host PATH to the openssl child process. Needed
    |               where the binary is not on the default search path.
    |
    | legacy        Add openssl's -legacy flag, required to read old PFX files
    |               (RC2/40-bit) under OpenSSL 3.x.
    |
    */

    'certificate' => [
        'use_path_env' => env('A1_PDF_SIGN_USE_PATH_ENV', false),
        'legacy' => env('A1_PDF_SIGN_LEGACY_CERTIFICATE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signature seal
    |--------------------------------------------------------------------------
    |
    | Defaults for the visual seal stamped onto signed documents. font.size
    | accepts a FontSize case or its string value; driver accepts an
    | ImageDriver case or its string value.
    |
    */

    'seal' => [
        'driver' => env('A1_PDF_SIGN_IMAGE_DRIVER', 'gd'),

        'font' => [
            'path' => null,
            'size' => 'large',
            'color' => '#16A085',
        ],

        'background' => null,
    ],

];
