# The v2 modernisation, as planned

A record, not a specification. Nothing here is authoritative about how the
package behaves today — for that, read [the public API](../spec/public-api.md),
[the invariants](../spec/invariants.md) and [the quality policy](../spec/quality-policy.md).

It is kept because it answers questions the current code cannot: why v1 was
shaped the way it was, which alternatives were weighed, and where the finished
work diverged from the plan that produced it.

The full original text of the plan is preserved at tag `2.0.0`, as
`ARCHITECTURE-V2.md`.

---

## The planned architecture, and what was built instead

The plan's §2 sketched a target layout before any of it existed. Four parts of
that sketch were never built, and the sketch was never reconciled — which is the
drift this reorganisation exists to stop.

| Planned | Built | Why it diverged |
|---|---|---|
| `Signing/TcLibPdfSigner.php` + `Signing/TcpdfSigner.php` | `Signing/IncrementalSigner.php` | PR 7c swapped tc-lib-pdf for tc-lib-pdf-sign, so the package stopped rendering PDFs entirely. With no renderer there was no driver pair to choose between. |
| `Enums/SealPage.php` | `Data\SealPlacement::LAST_PAGE` | The page is one field of a placement, not a concept with its own behaviour. |
| `->approval()` / `->certify()` / `->ltv()` | `->profile()` | The PAdES level determines all three. Choosing the level once is the same decision, expressed as one call instead of three that can contradict each other. |
| `Console/` | `Commands/` | Laravel's own convention. |
| `'temp_disk'`, `'signer' => 'tc-lib-pdf'` | `'temp_path'`, no `signer` key | The disk abstraction bought nothing once temporary files stopped living in `vendor/`; the signer key described a driver choice that no longer exists. |

The planned entry point was `A1PdfSign::certificate($pfx, $pass)->pdf(…)`. What
shipped puts a `newSignature()` in front of it, so the facade can carry one-shot
methods (`signFromFile`, `signFromPem`, `signFromUpload`) alongside the builder
without overloading a single name.

## Fonts — a blocker that evaporated

tc-lib-pdf could not emit any PDF without a generated font definition, not even
a signature-only document containing no text:

```
Com\Tecnick\Pdf\Font\Exception: unable to read file: helvetica.json
```

TCPDF 6 bundles 165 fonts in PHP format; tc-lib-pdf-font expects JSON. Not
interchangeable. The plan therefore added unforeseen scope: generate the core-14
metrics, ship them under `resources/fonts/`, and define `K_PATH_FONTS` from the
service provider.

**None of it was needed.** PR 7c removed tc-lib-pdf along with the rendering it
had been brought in for. The package appends revisions to bytes it already has
and never emits a document, so no font definition is ever loaded.

What survives is the inverse rule, and it is load-bearing: `K_PATH_FONTS` must
stay **undefined**, because tc-lib-pdf and TCPDF 6 read it in different formats
and defining it kills TCPDF silently. That now lives in
[the invariants](../spec/invariants.md).

## Quality tooling that was planned and not adopted

The plan's §6 listed a stack assembled before any of it was installed. Most of
it shipped; these did not.

- **Roave `backward-compatibility-check`.** Deferred in PR 11: it compares
  against a released tag, so it only became meaningful from 2.0.0 onward. Still
  worth having.
- **`rector/rector` + `driftingly/rector-laravel`.** Planned as a `--dry-run`
  gate; never wired in.
- **A `bc-check` CI job and a `test:arch` script.** Neither exists; arch tests
  run as part of the ordinary suite.
- **The arch rules as first drafted.** `legacy stays contained` expected
  `LSNepomuceno\LaravelA1PdfSign\Sign` to be deprecated — that namespace was
  deleted outright rather than deprecated, so the rule became
  `no deprecated namespace lingers`. `no shell-out outside the CLI driver`
  pinned the exception to `OpenSslCliCertificateReader`; PR 12 rebuilt
  `ProcessRunner` on `Illuminate\Process\Factory` and widened the rule to that
  single helper instead.

Several PHP-8.4 features were listed as targets and deliberately not taken:
asymmetric visibility on the value objects, property hooks on `Certificate`, and
`#[\NoDiscard]` on the fluent methods. The criterion the plan set for itself —
a feature earns adoption when it removes code or removes a class of bug — ruled
them out on its own terms.

## The PHPUnit → Pest migration, and a codemod that failed

`pestphp/pest-plugin-drift` was meant to convert the `TestCase` classes
automatically. On this codebase it corrupted `tests/TestCase.php`, leaving
method bodies without their signatures, emitted `uses()` above the import block,
and scaffolded `Feature/` and `Unit/` directories the package does not want.

With six test files totalling ~590 lines, converting by hand was both safer and
faster. The plugin was never taken as a dev dependency.

The lesson generalises, and it is why no codemod has been adopted since: a
codemod is worth it at scale, not at this size.

## Where v1 stood

The diagnosis that motivated all of it — the private key written in plain text
inside the consumer's `vendor/`, the password travelling on the command line
where `ps` exposed it, six global functions as the entire public API, validation
that reported a tampered document as valid — is recorded in the plan at tag
`2.0.0` and summarised for users in [UPGRADE.md](../../UPGRADE.md).
