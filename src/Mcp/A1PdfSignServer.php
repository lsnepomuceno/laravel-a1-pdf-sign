<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Mcp;

use Composer\InstalledVersions;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\{Instructions, Name};
use Laravel\Mcp\Server\Contracts\Transport;
use LSNepomuceno\LaravelA1PdfSign\Mcp\Tools\{ListSignatureFields, ValidatePdfSignature};

/**
 * The package's tools as an MCP server, ready to register.
 *
 * **It reads and never signs.** Both tools answer questions about documents on
 * the disks the application opened to agents, and neither writes a byte.
 * Signing through an agent is `Ai\Tools\SignPdf`, which exists only on the AI
 * SDK side, because MCP leaves approval to the client and a signature with
 * legal weight cannot rest on a setting somebody may have switched to "always
 * allow" (docs/decisions/0040-agents-read-through-mcp-and-sign-through-the-ai-sdk.md).
 *
 * **The package does not register it.** Where it is served, and who may call
 * it, is the application's decision, in `routes/ai.php`:
 *
 * ```php
 * Mcp::web('/mcp/signatures', A1PdfSignServer::class)->middleware('auth:sanctum');
 * Mcp::local('signatures', A1PdfSignServer::class);
 * ```
 *
 * Not final, on purpose: an application adding its own tools to the same
 * server extends this and appends to `$tools`, rather than copying the list
 * and missing the next tool this package adds.
 */
#[Name('A1 PDF Sign')]
#[Instructions(<<<'MARKDOWN'
    Answers questions about digitally signed PDF documents stored on this application's disks.

    - `validate_pdf_signature` verifies every signature in a document and reports who signed it, whether each
      signature verifies, the PAdES profile it satisfies and when it was signed.
    - `list_signature_fields` lists the signature fields a document declares, and which are still empty.

    Documents are addressed by a disk and a path relative to it. Only the disks offered in each tool's schema are
    reachable. Neither tool changes a document, and neither can sign one.
    MARKDOWN)]
class A1PdfSignServer extends Server
{
    protected array $tools = [
        ValidatePdfSignature::class,
        ListSignatureFields::class,
    ];

    /**
     * Reports this package's installed version rather than a literal.
     *
     * A version written into the class is a second place to bump on every
     * release, and the one that gets forgotten. Composer already knows.
     */
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);

        $this->version = InstalledVersions::getPrettyVersion('lsnepomuceno/laravel-a1-pdf-sign') ?? 'dev';
    }
}
