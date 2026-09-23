# 0041: Agents are authorised per document, by the application's gate

**Status:** implemented.

## Context

[0040](0040-agents-read-through-mcp-and-sign-through-the-ai-sdk.md) shipped the
agent tools in 3.1.0 with one boundary: the list of disks an application opens
to agents. It said so plainly in its consequences: the read tools answer for
any document on an open disk, to anybody who reaches them, and the guide
recommended a dedicated disk for that reason.

That is a boundary around a place, and most applications need one around a
person. A contracts disk holds every customer's contracts; the user asking an
agent about one of them may see their own and nobody else's. With 3.1.0 the
only way to express that was a disk per user, which nobody does.

Reviewing both SDKs for what else applied turned up one more gap, and several
things that looked like improvements and were not.

## Decision

### Who may reach which document is the application's gate

Two abilities, named by `Agents\Ability`:

| Ability | Arguments after the user | Asked by |
|---|---|---|
| `a1-pdf-sign.agents.read` | disk, path | `validate_pdf_signature`, `list_signature_fields` |
| `a1-pdf-sign.agents.sign` | disk, path, destination disk, destination path | `sign_pdf` |

**Laravel already has the answer to "may this user do this to that",** and an
application already writes its policies there. So the package asks
`Gate`, through `Agents\DocumentAccess::authorize()`, and invents nothing.

**An ability nobody defined allows.** The disk list stays the control 3.1.0
promised, and an application upgrading does not find every agent call refused
because it has not written a policy yet. Once it defines one, Laravel's usual
rules apply, including that a guest is refused unless the ability's user is
nullable. That matters for an MCP server over stdio, which has no user at all.

**The gate is asked before the document is looked at.** A user who may not read
`salaries.pdf` gets "you may not read", never "there is no document", so a
refusal is not an oracle for what exists.

**Signing asks with both ends.** Where a signed copy lands is part of the
decision, so the destination is an argument too.

**Approval is still asked for a call the gate will refuse.** The tool never
answers "no approval needed". The text the person sees says the call will be
refused, and the refusal happens when it runs.

### A document an agent names has a size limit

`a1-pdf-sign.agents.max_bytes`, **50 MB by default**. The model chooses the
file and the engine holds it in memory, so a prompt is otherwise a way to make
a worker load whatever is largest on the disk. It is checked from the disk's
own metadata, before a byte is read. Null removes it.

### Recommended rather than built

A `throttle` middleware on the MCP route, in the guide. Validation opens a
process and verifies a CMS, and
[0035](0035-the-audit-trail-is-opt-in.md) already warned that it is the call an
attacker can make in bulk. The route is the application's, so the rate is too.

## Consequences

- `Agents\Ability` and `DocumentAccess::authorize()` / `allows()` are public, so
  an application's own tools can ask the same question the same way.
- **3.1.0 applications see one change without editing anything**: a document
  over 50 MB is now refused by the agent tools. Nothing else moves until they
  define an ability.
- `DocumentAccess` depends on `Illuminate\Contracts\Auth\Access\Gate`, which
  every Laravel application binds.

## Alternatives rejected

| | Why not |
|---|---|
| A config key naming a policy class | Laravel already has two places for authorisation, gates and policies, and a third would be this package's |
| Refuse when no ability is defined | Every 3.1.0 application would find its agents refused after a minor release, for not having written something 3.1.0 never asked for |
| Authorise after checking the document exists | The error would tell a user who may not read a path whether it exists |
| Skip approval when the gate would refuse | The tool would have a path that answers "no approval needed", which is the one answer it must never give |
| **MCP completions for the `path` argument** | The protocol offers completions for prompts and resources, not for tools. Adding a prompt to get them would mean listing a disk's files to the client, a disclosure the tools do not make today |
| **`shouldRegister()` to hide the tools when no disk is open** | The AI SDK's `McpServerTool` never calls it, so the two sides would disagree. And a hidden tool hides the configuration mistake with it: an open-but-empty list is better answered by a refusal that names it |
| **The package server behind `ToolSearch`** | See [0040](0040-agents-read-through-mcp-and-sign-through-the-ai-sdk.md): it saves nothing at two tools and costs the read-only annotations |
| `#[Cacheable]`, icons | Nothing measurable at two tools. An application that wants either extends the server |

## Outcome

Built as decided. The existing refusals needed no change: `DocumentAccess`
already sat in front of every path, so the gate is one method there and one
call in each tool, placed before the call each tool already made.
