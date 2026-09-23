# 0040: Agents read through MCP, and sign through the AI SDK

**Status:** implemented.

## Context

Laravel shipped two first-party packages for AI within ten days of each other:

- **`laravel/mcp`**, 1.0 on 2026-09-14. An application defines tools, resources
  and prompts and serves them to any MCP client: Claude Code, Cursor, Laravel
  Boost, anything speaking the protocol.
- **`laravel/ai`**, 1.0 on 2026-09-23. An application defines agents, gives
  them tools, and prompts them against a provider. It has a human approval flow
  for tools: an `Approvable` tool pauses the run, the application shows a person
  what the call would do, and the run resumes with their decision.

This package is the Laravel half of a signing library
([0039](0039-the-core-lives-in-signet-pdf.md)), and both of these are Laravel
infrastructure. So the question was not whether a Laravel application will want
an agent to answer "is this contract signed, and by whom", but where that
belongs and how far it goes.

Two facts, read from the source of both 1.0 releases rather than from their
documentation, decided the shape.

**The AI SDK runs MCP tools.** `GeneratesText` checks every tool an agent
returns, and anything that is a `Laravel\Mcp\Server\Tool` is wrapped in
`Laravel\Ai\Tools\McpServerTool`, with structured content handed to the model as
JSON. A tool written once for `laravel/mcp` therefore reaches MCP clients *and*
AI SDK agents. The reverse is not true.

**Approval exists on one side only.** `McpServerTool` does not implement
`Approvable`, so an MCP tool running inside an agent is never gated. And on the
MCP side, whether a call runs is the client's decision: a client may ask, or may
have been told to always allow. A signature made with an ICP-Brasil certificate
has the legal weight of a handwritten one, and cannot rest on a setting somebody
switched to "always allow".

## Decision

**Reading goes through `laravel/mcp`. Signing goes through `laravel/ai`. Both
are optional.**

### What is on each side

| | `laravel/mcp` | `laravel/ai` |
|---|---|---|
| Classes | `Mcp\Tools\ValidatePdfSignature`, `Mcp\Tools\ListSignatureFields`, `Mcp\A1PdfSignServer` | `Ai\Tools\SignPdf`, with `Contracts\SigningCertificateResolver` |
| Reached by | MCP clients, and AI SDK agents through the SDK's wrapper | AI SDK agents |
| Writes | nothing | a signed copy, after approval |

The read tools are **not** written a second time for the AI SDK. They already
reach it, and two implementations of the same operation is the defect 0039
exists to refuse, at a smaller scale.

Shared code that needs neither SDK lives in `Agents\`: the path guard, the
argument schema and the call ledger. It is tested in the CI job that removes
both SDKs.

### Optional, and confined

Both packages are in `require-dev`, so the suite and PHPStan see them, and in
`suggest`, so nobody who only signs inherits `illuminate/database`, conversation
migrations and `laravel/prompts`. `conflict` pins both to `^1.0`: `suggest`
constrains nothing, and without the conflict an application on a 0.x or a future
2.0 would install cleanly and fail on the first tool call.

A class naming an absent SDK cannot be loaded, so each SDK is confined to one
directory, `src/Ai` or `src/Mcp`, and nothing outside it names the classes
inside it. `tests/Project/ArchTest.php` enforces both halves, and a CI job runs
the suite with both SDKs removed.

**The package registers nothing.** Not a route, not a tool, not a binding. Where
an MCP server is served and who may call it is the application's decision, in
`routes/ai.php`, the same way the SSRF surface of the HTTP transport is the
application's ([0027](0027-the-transport-is-a-seam.md)).

### A model's path is an attack surface

A tool taking a disk and a path from a model is a file-reading primitive, and
whoever writes the prompt steers the model. So every tool resolves its
arguments through `Agents\DocumentAccess`, which refuses:

- a disk missing from `a1-pdf-sign.agents.disks`, **which is empty by default**,
  so installing an SDK and adding a tool reaches nothing until somebody chooses;
- an absolute path, `..` anywhere, a backslash, a null byte;
- a file whose name does not end in `.pdf`;
- a destination that exists. A signed copy never overwrites.

`..` is refused rather than normalised. Flysystem would turn `a/../b` into `b`
and refuse only what escapes the disk's root, which is right for a filesystem
and wrong here: a path that climbs is a path being steered.

The schema offers the open disks as an enum, because a model shown the valid
values rarely invents one. The enum is a hint. `DocumentAccess` is the control.

### The CPF stays out of the model unless asked

`ValidatePdfSignature` reports the signer's name. The CPF or CNPJ beside it is
personal data under the LGPD, and handing it to a model provider is a decision
the application makes on purpose: `a1-pdf-sign.agents.expose_registry`, off by
default.

The approval text shown to a person does carry the registry, since that person
is normally the certificate's holder, and a CPF is how a Brazilian signer tells
two certificates with the same name apart. That text goes to the application,
not to the model.

### Four refusals in the signing tool

1. **Approval cannot be switched off.** `SignPdf::shouldRequestApproval()`
   always returns an `Approval`, whatever the arguments. `withoutApproval()`
   throws. `requireApproval($note)` is allowed, and only adds words in front of
   the description.
2. **The certificate never passes through the model.** The schema has a
   document, a destination, a profile and a reason. The key comes from
   `Contracts\SigningCertificateResolver`, which the application binds. Unbound,
   the tool raises `Exceptions\SigningCertificateUnavailable` naming what to
   bind, rather than the container's "not instantiable".
3. **A repeated call signs once.** After a person approves, the same call can
   still arrive twice: a provider retrying on a timeout, a queued run picked up
   again, a double click. The AI SDK hands each call the provider's id and
   describes it as "usable as an external idempotency key". `Agents\ToolCallLedger`
   claims it with the cache's `add()` before anything is signed; a repetition
   gets the first call's result back. A call that fails releases its claim, so
   a corrected retry is not blocked for a day.
4. **Everything the model names goes through `DocumentAccess`**, source and
   destination both, and both are checked before the certificate is opened.

Refusals a model can act on, a closed disk or an occupied path, come back as the
tool's result so the model can correct itself. A wiring mistake is thrown, since
it is for the developer to see and not for the model to work around.

Once signed, `Events\DocumentSignedByAgent` is dispatched with signet's
`SigningReceipt`, which carries no PDF and no secret, and the user as an id
rather than a model, so the event queues cleanly.

### What is not shipped

**No agent.** An agent carries instructions, a provider, a model and a cost,
and every one of those is the application's decision. The package ships tools;
the application builds the agent that uses them.

## Consequences

- `composer.json` gains two `suggest` entries, two `require-dev` entries and a
  `conflict` block. It also loses the suggestion of
  `lsnepomuceno/laravel-brazilian-ceps`, which had nothing to do with signing.
- `config/a1-pdf-sign.php` gains `agents.disks`, `agents.expose_registry` and
  `agents.idempotency.{store,ttl}`, all scalars (invariant 3). Adding them is a
  minor release.
- CI gains a job that removes both SDKs and runs everything except the `mcp` and
  `ai` groups. It is what makes "optional" a checked claim.
- **The claim in the ledger is exactly as atomic as the cache store.** Redis,
  Memcached, DynamoDB and the database store implement `add()` atomically; the
  file and array stores do not. The store is configurable for that reason, and
  the guide says so.
- The read tools answer for any document on an open disk, to anybody the
  application lets reach the MCP route. There is no per-document authorisation
  in them, which is why a dedicated disk is what the guide recommends opening.
  *Superseded by [0041](0041-agents-are-authorised-per-document.md), which asks
  the application's gate per document.*

## Alternatives rejected

| | Why not |
|---|---|
| Everything on the AI SDK | The read tools would reach AI SDK agents only, and an MCP client, which is where a developer asks these questions today, would get nothing |
| Everything on MCP, signing included | Approval would be the client's, and a client can be set to always allow. A signature with legal weight needs the tool to insist, and only the AI SDK lets it |
| The read tools written for both SDKs | The AI SDK already runs the MCP ones. Two copies of an operation is where one of them goes stale |
| Require the SDKs | Every application that only signs would install a database layer, migrations and a prompt library it never uses |
| A separate package | Three small tool classes and a guard, against a second repository, a second release and a second compatibility table. It stays here while it stays this small |
| Take the certificate and password as tool arguments | The password would sit in the provider's logs and the conversation store. That is not a risk to weigh, it is the outcome |
| Register the MCP route from the provider | The package would be deciding the application's authentication, and serving documents to whoever reached a URL it did not choose |
| Ship an agent | Instructions, provider, model and cost are the application's decisions |

## Outcome

Built as decided, in one pull request. Four things the decision did not
anticipate, all found while writing the tests rather than after:

- **The engine raises for an unsigned document**, with the same exception it
  raises for a signature it cannot parse. `ValidatePdfSignature` therefore
  cannot report `signed: false` truthfully, and relays the engine's own
  sentence as an error instead of claiming more than it knows.
- **`HasTools::tools()` is declared as returning SDK tools only**, although the
  SDK wraps a bare MCP tool at runtime. An application under PHPStan gets an
  error for relying on the wrapping, so the guide wraps the MCP tools in
  `McpServerTool` by hand, and the suite tests both.
- **An approver can edit the call.** `Decision::edit($arguments)` resumes the
  run with different arguments, which the SDK executes without asking again.
  The tool re-validates them through `DocumentAccess` like any other call, but
  the text the person approved described the original. The guide tells an
  application that offers editing to show the edited call before resuming.
- **`tests/Project/ArchTest.php` read the package's own name as a verification
  tool.** It scans string literals in `src/` for `pdfsig`, which `A1PdfSign`
  contains. Two error messages were reworded rather than the gate loosened.

**The package server does not group its tools behind `ToolSearch`**, and that
was decided after 3.1.0 shipped rather than before, when it was pointed out.
`laravel/mcp` 1.0 can hide a server's tools behind two meta-tools,
`search_tools` and `execute_tools`, so a client does not load every schema up
front. At two tools that saves nothing and adds a search before every use. It
also costs the annotations: a client sees `execute_tools`, which the SDK marks
open-world and not read-only, so a client that could run
`validate_pdf_signature` without asking would ask for every call. And
`McpServerTool` wraps a tool, which `ToolSearch` is not, so grouped tools stop
reaching AI SDK agents that way. An application with many tools of its own
groups ours with them, which works unchanged and is tested
(`tests/Mcp/ServerTest.php`).
