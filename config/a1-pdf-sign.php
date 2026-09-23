<?php

declare(strict_types=1);

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

        /*
        | The policy a signature declares it was made under. Null declares
        | none, which is what every signature produced before 3.0.
        |
        | Name an ICP-Brasil policy and the current version of it is used,
        | since a policy is superseded on a date and an application meaning
        | "AD-RT" means the one in force:
        |
        |   'policy' => 'ad-rb' | 'ad-rt' | 'ad-rc' | 'ad-ra'
        |
        | Or name a specific version by its OID, or supply the three fields
        | for a policy from anywhere else:
        |
        |   'policy' => [
        |       'oid' => '2.16.76.1.7.1.11.1.2',
        |       'digest_algorithm' => 'sha256',
        |       'digest' => '…',
        |       'uri' => 'https://…',
        |   ],
        */

        'policy' => env('A1_PDF_SIGN_POLICY'),

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

        /*
        | Intermediate certificates to embed when the bundle carries none.
        |
        | A set of files rather than a sequence: the chain is built from what
        | they contain rather than trusted in the order they are listed.
        */

        'chain_paths' => [],
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

    /*
    |--------------------------------------------------------------------------
    | AI agents
    |--------------------------------------------------------------------------
    |
    | Read by the optional agent tools: the MCP server and its two read-only
    | tools (laravel/mcp), and the signing tool (laravel/ai). Nothing here does
    | anything until one of those packages is installed and a tool is used.
    |
    | disks            The Storage disks an agent may read from and write to.
    |                  Empty, the default, opens none: every tool refuses every
    |                  call. A disk scoped to the documents agents should see
    |                  is safer than opening a general one.
    |
    | expose_registry  Hand the model a signer's CPF or CNPJ. Off by default:
    |                  the name is enough to answer "who signed", and the
    |                  number is personal data sent to a model provider.
    |
    | idempotency      Where the signing tool records the calls it has already
    |                  signed, so a retried call signs once. Null uses the
    |                  default cache store. Pick one whose add() is atomic
    |                  (redis, memcached, database, dynamodb) in production.
    |
    */

    'agents' => [
        'disks' => [],

        'expose_registry' => env('A1_PDF_SIGN_AGENTS_EXPOSE_REGISTRY', false),

        'idempotency' => [
            'store' => env('A1_PDF_SIGN_AGENTS_CACHE_STORE'),
            'ttl' => 86400,
        ],
    ],

];
