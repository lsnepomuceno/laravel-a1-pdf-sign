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

    /*
    |--------------------------------------------------------------------------
    | Signature profile
    |--------------------------------------------------------------------------
    |
    | legacy     ISO 32000-1 detached CMS. Widest reader support.
    | pades-b-b  PAdES baseline. The default.
    | pades-b-t  B-B plus an RFC 3161 timestamp, needs timestamp.url below.
    | pades-b-lt B-T plus a Document Security Store.
    | pades-b-lta B-LT plus an archive timestamp.
    |
    | Every level above legacy carries the ESS signing-certificate-v2 attribute.
    |
    */

    'signature' => [
        'profile' => env('A1_PDF_SIGN_PROFILE', 'pades-b-b'),

        'digest_algorithm' => env('A1_PDF_SIGN_DIGEST', 'sha256'),

        'timestamp' => [
            'url' => env('A1_TSA_URL'),
            'username' => env('A1_TSA_USERNAME'),
            'password' => env('A1_TSA_PASSWORD'),
            'timeout' => 20,

            // A timestamp authority is a third party over the public internet,
            // and a transient failure would otherwise fail the signature.
            // Attempts, not retries: 1 means try once and do not retry.
            'attempts' => 3,
            'backoff' => 200,
        ],

        'ltv' => [
            'timeout' => 10,

            // Revocation material degrades the profile rather than failing it,
            // so this is deliberately less patient than the timestamp above.
            'attempts' => 2,
            'backoff' => 100,
        ],
    ],

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

        /*
         * Honour the artwork's alpha channel instead of flattening it.
         *
         * A transparent seal is stored as raw samples with an /SMask, since PDF
         * has no PNG filter, which costs more bytes than the JPEG it replaces.
         * Set this to false for the smaller, opaque rectangle.
         */
        'transparent' => true,

        /*
         * Where the seal's text sits on the artwork, in pixels from the top
         * left. One row per line; a line with no row is not drawn.
         */
        'text' => [
            'x' => 160,
            'rows' => [80, 150, 250],
        ],
    ],

];
