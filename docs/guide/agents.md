# AI agents

An agent can answer "is this contract signed, and by whom" by reading the
document itself, and, with a person's approval, sign one. The package provides
the tools for both, on top of Laravel's own AI packages. It does not provide
the agent: instructions, provider, model and cost are yours to choose.

| | Needs | Reached by | What it does |
|---|---|---|---|
| `validate_pdf_signature` | `laravel/mcp` | MCP clients and AI SDK agents | verifies every signature, says who signed |
| `list_signature_fields` | `laravel/mcp` | MCP clients and AI SDK agents | lists the fields, signed and empty |
| `sign_pdf` | `laravel/ai` | AI SDK agents | signs, **after a person approves** |

**Reading goes through MCP and signing through the AI SDK**, and the split is
deliberate. The AI SDK runs MCP tools, so a read tool written once reaches both
worlds. Signing needs a person to approve every call, and only the AI SDK lets
a tool insist on that: on the MCP side, approval is whatever the client was set
to do ([0040](/decisions/0040-agents-read-through-mcp-and-sign-through-the-ai-sdk)).

## Installing

Both SDKs are optional. Install the ones you use:

```bash
composer require laravel/mcp   # the two read tools, and the MCP server
composer require laravel/ai    # the signing tool
```

The package requires `^1.0` of each and refuses to install beside anything
else, so a version mismatch fails at `composer require` rather than on the
first tool call. Nothing is registered for you: no route, no tool, no binding.

## Opening a disk

**Out of the box, no tool reaches anything.** Agents address documents by a
`Storage` disk and a path, and a disk is reachable only once you list it:

```php
// config/a1-pdf-sign.php
'agents' => [
    'disks' => ['contracts'],
],
```

Open a disk that holds what agents should see and nothing else. A disk scoped to
a directory is the simplest way to get there:

```php
// config/filesystems.php
'contracts' => [
    'driver' => 's3',
    'bucket' => env('AWS_BUCKET'),
    'root' => 'contracts',
    // …
],
```

The tools answer for **any** document on an open disk, to anybody who can reach
them. They carry no per-document authorisation, so the disk is the boundary.

### What is refused

Every path a model sends goes through `Agents\DocumentAccess` before a byte is
read, and a tool's schema offers the open disks as an enum. The enum is a hint
to the model; the guard is the control:

| Refused | Because |
|---|---|
| a disk not in `agents.disks` | nobody opened it |
| `/etc/deal.pdf`, `C:/deal.pdf` | absolute: a path is relative to its disk |
| `2026/../../salaries.pdf` | `..` anywhere is refused, not normalised |
| `contracts\deal.pdf`, a null byte | not a path a disk understands |
| `notes.txt`, `deal.pdf.php` | only names ending in `.pdf` |
| a destination that exists | a signed copy never overwrites |

The refusal comes back to the model as the tool's error, worded so it can
correct itself: it names the disks that are open, never a disk's root.

**A path is relative to its disk, and never starts with the disk's name.**
`deal.pdf` on the disk `contracts` is `deal.pdf`, not `contracts/deal.pdf`. The
tools tell the model so in their schema, because a model left to guess glues
the disk's name to the front, as DeepSeek did the first time this was tried
against a real provider.

## The CPF stays out of the model

The validation tool reports each signer's name. The CPF or CNPJ on an
ICP-Brasil certificate is personal data, and sending it to a model provider is
your decision to make:

```php
'agents' => [
    'expose_registry' => env('A1_PDF_SIGN_AGENTS_EXPOSE_REGISTRY', false),
],
```

## Where to go next

- [Reading through MCP](/guide/mcp): serving the tools to Claude Code, Cursor or
  Boost, and handing them to an AI SDK agent.
- [Signing through an agent](/guide/agent-signing): the approval flow, choosing
  the certificate, and what happens after.
- [Testing](/guide/testing#agents): faking the model and asserting on the tools.
