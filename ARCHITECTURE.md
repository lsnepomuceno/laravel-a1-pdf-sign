# Architecture

Documentation is split by **lifecycle**, not by topic. Each file below changes
at a different rate, and mixing them in one document is what let the previous
one drift: `ARCHITECTURE-V2.md` described a `TcLibPdfSigner` that was never
built, and cited a §12 that never had a section.

`tests/Project/SpecTest.php` fails when a reference into any of these stops resolving.

## Living: must be true of the code today

| | |
|---|---|
| [docs/spec/invariants.md](docs/spec/invariants.md) | Rules that break the product or the project when violated. **Read before touching `src/Adapters`, `src/Io` or the dependency list.** |
| [docs/spec/public-api.md](docs/spec/public-api.md) | What the package exposes, and what changing it costs. |
| [docs/spec/quality-policy.md](docs/spec/quality-policy.md) | The gates a change has to pass, and why each sits where it does. |
| [docs/spec/conventions.md](docs/spec/conventions.md) | How the code is written: reach for the framework before writing a helper, and use an enum where a set of values exists. |

## Decisions: why the design is what it is

[docs/decisions/](docs/decisions/README.md), one numbered file per decision.
The number is the identifier, so it survives the next reorganisation; `§3i` did
not survive this one.

Each carries an outcome section when what shipped differed from what was
decided. A decision record whose outcome is never written back is how a document
drifts away from the code it describes.

## History: frozen, kept because it answers what the code cannot

| | |
|---|---|
| [docs/history/v2-modernization.md](docs/history/v2-modernization.md) | Where v1 stood, what the v2 plan proposed, how the roadmap was executed, and where the result diverged. |
| [docs/history/decision-log.md](docs/history/decision-log.md) | Questions that were put and when they were answered, including the ones the plan left open. |
| [docs/history/the-core-left.md](docs/history/the-core-left.md) | What the extraction to signet-pdf actually cost, against what 0039 predicted. |

## For consumers, not contributors

[UPGRADE.md](UPGRADE.md) maps every removed or changed API to its replacement.
[README.md](README.md) is the usage documentation.

## The engine is not here

Everything that signs, validates, reads a certificate or renders a seal is in
[`lsnepomuceno/signet-pdf`](https://github.com/lsnepomuceno/signet-pdf), which
has its own invariants, decisions and gates. A defect in any of those belongs
there; a defect in the wiring, the config file, an artisan command, a disk
source or the fake belongs here
([0039](docs/decisions/0039-the-core-lives-in-signet-pdf.md)).
