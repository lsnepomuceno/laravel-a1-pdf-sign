# Reading through MCP

Two read-only tools, written for `laravel/mcp`, reachable by any MCP client and
by any AI SDK agent. Neither writes a byte, and neither reaches the network:
validation fetches nothing.

```bash
composer require laravel/mcp
```

Then [open a disk](/guide/agents#opening-a-disk). Until you do, both tools
refuse every call.

## Serving them to an MCP client

`Mcp\A1PdfSignServer` groups both tools. Register it in `routes/ai.php`, with
the authentication you want in front of it:

```php
use Laravel\Mcp\Facades\Mcp;
use LSNepomuceno\LaravelA1PdfSign\Mcp\A1PdfSignServer;

// Over HTTP, for a remote client
Mcp::web('/mcp/signatures', A1PdfSignServer::class)->middleware('auth:sanctum');

// Over stdio, for a client on the same machine: `php artisan mcp:start signatures`
Mcp::local('signatures', A1PdfSignServer::class);
```

**The package registers no route.** Who reaches your documents is your
decision, and a web route with no middleware is a route anybody with the URL
can call.

### Adding your own tools to the same server

The class is not final, so an application extends it rather than copying the
list and missing the next tool this package adds. Append in the constructor,
since a child's property default cannot refer to its parent's:

```php
use Laravel\Mcp\Server\Contracts\Transport;

final class ContractsServer extends A1PdfSignServer
{
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);

        $this->tools[] = ContractStatus::class;
    }
}
```

## Handing them to an AI SDK agent

The AI SDK runs MCP tools. Wrap each one in `McpServerTool` and return it from
`tools()`:

```php
use Laravel\Ai\Contracts\{Agent, HasTools};
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\McpServerTool;
use LSNepomuceno\LaravelA1PdfSign\Mcp\Tools\ListSignatureFields;
use LSNepomuceno\LaravelA1PdfSign\Mcp\Tools\ValidatePdfSignature;

final class ContractAssistant implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You answer questions about the company\'s signed contracts.';
    }

    public function tools(): iterable
    {
        return [
            new McpServerTool(new ValidatePdfSignature()),
            new McpServerTool(new ListSignatureFields()),
        ];
    }
}
```

The SDK also wraps a bare MCP tool on its own, so `new ValidatePdfSignature()`
without the wrapper works at runtime. **Wrap it anyway if you run PHPStan**:
`HasTools::tools()` is declared as returning SDK tools only, and the bare form
is reported as an error. The wrapper is the same one the SDK would apply.

Structured content reaches the model as JSON. A refusal reaches it as text
starting with `MCP tool error:`, which is enough for it to tell the user what
went wrong.

## `validate_pdf_signature`

**Arguments:** `disk`, `path`.

```json
{
  "disk": "contracts",
  "path": "2026/deal_signed.pdf",
  "signed": true,
  "valid": true,
  "signature_count": 1,
  "certified": false,
  "certification_level": null,
  "accepts_further_signatures": true,
  "trusted": null,
  "document_findings": [],
  "signatures": [
    {
      "signer": "MARIA DA SILVA",
      "registry": null,
      "verified": true,
      "is_timestamp": false,
      "covers_whole_document": true,
      "profile": "pades-b-b",
      "signed_at": "2026-09-23T14:02:11+00:00",
      "attested_at": null,
      "revocation": "unknown",
      "findings": ["revocation-unknown"]
    }
  ]
}
```

What each field means is [the validation guide](/guide/validation). A few are
worth knowing before an agent paraphrases them:

- **`valid` means the CMS verifies against the bytes**, not that the signer is
  trusted. `trusted` is always `null` here: no trust store was consulted, and
  whom to trust is your policy. An application with one writes its own tool.
- **`signed_at` is the signer's own clock**, a claim. `attested_at` is a
  timestamp authority's, and is null unless a timestamp verified.
- **`findings` are facts, not verdicts.** `revocation-unknown` at `pades-b-b`
  is ordinary: nothing was embedded to decide it.
- **`registry` is null unless you set `agents.expose_registry`.**

**A document with no signature is an error**, not `"signed": false`. The engine
raises for it with the same exception it uses for a signature it cannot parse,
so the tool cannot tell the two apart and relays the engine's sentence rather
than guessing.

An encrypted document is also an error: the tool takes no document password,
since that would put it in the model's context.

## `list_signature_fields`

**Arguments:** `disk`, `path`.

```json
{
  "disk": "contracts",
  "path": "templates/employment.pdf",
  "field_count": 2,
  "unsigned_count": 1,
  "fields": [
    { "name": "SignatureManager", "signed": true, "page": 3, "visible": true },
    { "name": "SignatureEmployee", "signed": false, "page": 3, "visible": true }
  ]
}
```

The same as `php artisan pdf:fields`. `page` is zero for a field that declares
none, and `visible` is false for a field with no area, which signs without a
seal ([0013](/decisions/0013-signing-into-an-existing-field)).

## Annotations

Both tools declare `readOnlyHint`, `idempotentHint`, and `openWorldHint: false`.
A client that honours annotations can run them without asking, which is the
point: reading is safe, and asking about it trains people to click "allow" on
everything, including the call that is not.
