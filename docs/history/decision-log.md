# Decision log

Questions that were put, and how they were answered. Append-only: an entry is
never edited once its answer is recorded, only superseded by a later one.

Where a decision produced a design still in force, the reasoning lives in
[docs/decisions/](../decisions/README.md). This log records *that it was
decided*, and when. The two are not duplicates: a decision record explains a
design, a log entry dates a choice.

## Settled during the v2 work


| # | Question | Decision |
|---|---|---|
| 1 | Laravel floor | **Laravel 13**, revised in PR 12. Laravel 12 reaches PHP 8.5 and is still supported, but it pins `symfony/process ^7.2` while Pest 5 needs `^8.1`, so the cell cannot even be installed ([0005](../decisions/0005-php-and-laravel-floor.md)) |
| 2 | Mutation: Infection or Pest? | **`pest-plugin-mutate`**: one tool fewer, one runner, one report ([the quality policy](../spec/quality-policy.md)) |
| 3 | Attributes and modern features | **Adopt**, under the criterion "removes code or removes a class of bug" ([the quality policy](../spec/quality-policy.md)). `#[\SensitiveParameter]` enters as a security fix |
| 4 | PDF engine | **Migrate to `tc-lib-pdf`**: TCPDF 6 is officially deprecated; the migration unlocks LTV and TSA (the engine migration below). Legacy driver kept as optional |
| 5 | Multi-signature | **Our own incremental writer**, clean-room from ISO 32000-1, since no dependency delivers this ([0006](../decisions/0006-incremental-revision.md)) |
| 6 | Use `ddn/sapp`? | **No, under no circumstances**: not `require`, not `require-dev`, not `suggest`. LGPL is incompatible with porting code into an MIT package, and it is a legacy project. **Conceptual** reference only; clean-room implementation over tc-lib-pdf's building blocks, with an arch test enforcing the rule ([0006](../decisions/0006-incremental-revision.md)) |
| 7 | PHP floor: 8.4 or 8.3? | ✅ **8.4**, applied in PR 1. Keeps the toolchain on one Pest major and unlocks property hooks, `private(set)` and `#[\Deprecated]` |
| 8 | Multi-signature in v2.0 or v2.1? | ✅ **v2.0.** PR 0b closed with 3/3 valid signatures ([0006](../decisions/0006-incremental-revision.md)), so the risk that would justify deferring did not materialize |
| 15 | PEM: parallel pipeline, or a second entry onto the existing one? | ✅ **Second entry, one pipeline.** A separate contract and DTO would fork `CadesBuilder`, `CertificateVault`, `SealRenderer` and the public `Data\*` shape to gain nothing: PKCS#12 is *converted into* PEM before anything downstream runs, so the two are not peers. Divergence is confined to the entry point, where it is real, since PEM may be two files, and its key is often unencrypted ([0007](../decisions/0007-pem-second-entry-one-pipeline.md)) |

## Left open by the plan, and how they actually landed

Recorded here because the plan closed with them unresolved, and every one was
answered by the implementation without the table ever being updated. That gap is
what this reorganisation exists to close.

| # | Question | Outcome |
|---|---|---|
| 9 | `IncrementalSigner` as the default, or only for the second signature? | **Default.** Preserving the original bytes from the first signature onward is what removes the silent destruction of annotations and form fields, so making it conditional would have kept the defect for anyone signing once. |
| 10 | Keep the legacy driver? | **Reframed and kept.** Not a driver: `SignatureProfile::Legacy` reproduces the 1.x `/SubFilter` through the same pipeline, so byte-for-byte-comparable output survives without carrying deprecated dependencies. |
| 11 | phpseclib now or later? | **Never.** `Validation\DerReader` and `Pkcs7Reader` read ASN.1 in-package, and cryptographic verification shipped in 2.0 rather than 2.1. See [0002](../decisions/0002-asn1-parsed-in-package.md). |
| 12 | Full BC layer or a clean v2? | ✅ **Clean break.** A 3.0 is far enough out that a shim kept "until then" is kept indefinitely, and each one constrains the design it wraps. The PHP 8.4 / Laravel 13 floor already forces a deliberate upgrade, so the marginal cost of renaming call sites is small. [UPGRADE.md](../../UPGRADE.md) carries the mapping. |
| 13 | PHPStan `level: max` from the start, or a baseline? | **Both, then neither.** Measured at the time: 95 errors at level 5, 159 at level 8, 216 at max, so max cost only 57 extra baseline entries over level 8 while gating all new code at the strictest setting. The baseline was then **deleted rather than shrunk**; the gate is "no errors", not "no new errors". |
| 14 | Line-coverage gate? | **No.** Type coverage at 100% and mutation testing are more honest gates; line coverage stays informational. |

> The original plan proposed a PHP 8.2 / Laravel 10 floor. It was invalidated
> twice: first by the real tooling requirements, then by the realisation that
> the PHP *ceiling* of older Laravel versions, not the floor, is the limiting
> factor. See [0005](../decisions/0005-php-and-laravel-floor.md).
