# PoC 0 — tc-lib-pdf: LTV and RFC 3161 timestamping

Spike for PR 0 (see docs/history/v2-modernization.md). **Not production code.** It executes the
claims that §3g had only read from the source, and it blocks PRs 7 and 8.

**Result: 15/15 checks pass**, including a live timestamp from a public TSA.

## Running it

Requires a generated font (see [Fonts](#fonts-a-blocker-worth-recording) below):

```bash
docker compose -f .docker/compose.yaml run --rm -v /path/to/fonts:/fonts php \
    php /app/poc/tc-lib-pdf-ltv-tsa/run.php
```

## Result

```
tc-lib-pdf 8.67.2 | PHP 8.4.24

=== 1. baseline signature ===
  [PASS] produces a PDF — 18872 bytes
  [PASS] contains a /Sig object
  [PASS] contains /ByteRange
  [PASS] CMS is not an empty placeholder — 1476 bytes of DER
  [PASS] accepts PEM strings for privkey/signcert (no file://)

=== 2. LTV (DSS / VRI) ===
  [PASS] LTV run completes — 20605 bytes
  [PASS] /DSS present in the catalog
  [PASS] /VRI map present
  [PASS] /Certs entry present
  [PASS] LTV output differs from baseline — 20605 vs 18872 bytes

=== 3. LTV option validation ===
  [PASS] rejects a non-bool LTV option — Invalid signature LTV option: enabled

=== 4. reserved empty signature fields ===
  [PASS] empty approval widgets are emitted — 3 /FT /Sig fields
  [PASS] SigFlags set on AcroForm

=== 5. RFC 3161 timestamp ===
  TSA: https://freetsa.org/tsr
  [PASS] timestamped run completes — 38872 bytes
  [PASS] CMS grew, i.e. a token was embedded — 6135 vs 1476 bytes of DER

RESULT: 15 passed, 0 failed, 0 skipped
```

## The version gap

The repository's lock file pinned **tc-lib-pdf 8.0.85**; the current release is **8.67.2** —
67 minor versions of drift. Everything below only holds on the current release.

The upgrade also pulls in **`tecnickcom/tc-lib-pdf-sign` 1.1.1** transitively, a package that
did not exist at 8.0.85. It provides the PAdES profiles:

| Profile | `/SubFilter` | Adds |
|---|---|---|
| Legacy | `adbe.pkcs7.detached` | ISO 32000-1 detached CMS with ESS `signing-certificate-v2` |
| PAdES B-B | `ETSI.CAdES.detached` | CAdES signed attributes |
| PAdES B-T | `ETSI.CAdES.detached` | B-B + RFC 3161 signature timestamp |
| PAdES B-LT | `ETSI.CAdES.detached` | B-T + `/DSS` and `/VRI` validation material |
| PAdES B-LTA | + `ETSI.RFC3161` | B-LT + `/Type /DocTimeStamp` archive timestamp |

Upstream states these were validated against the EU DSS reference validator. That is a much
stronger proposition than the plain "LTV" §3g assumed, and it is the real argument for the
migration.

## Incremental update already exists upstream

`Output.php` in 8.67.2 has `appendIncrementalRevision()`, `buildIncrementalXref()`,
`buildIncrementalTrailer()` and `previousStartxref()` — all `protected`. They back the
post-signing `/DSS` (B-LT) and `/DocTimeStamp` (B-LTA) revisions.

This does **not** invalidate PoC 0b: there is still no public API to sign an *externally
supplied, already-signed* PDF, which is this package's core use case. But it does mean PR 7b
should first try inheriting that machinery instead of shipping the writer from PoC 0b.

## Fonts — a blocker worth recording

**tc-lib-pdf cannot emit any PDF without a generated font definition**, not even a
signature-only document with no text. A plain `composer install` ships none: the font data is
built by `make fonts` in the upstream repository.

```
Com\Tecnick\Pdf\Font\Exception: unable to read file: helvetica.json
```

TCPDF 6 bundles 165 fonts, but in PHP format; tc-lib-pdf-font expects JSON. Not
interchangeable.

The fix used here, and the proposed path for PR 7 — convert the core-14 AFM metrics that
`tecnickcom/tc-font-mirror` ships:

```bash
php vendor/tecnickcom/tc-lib-pdf-font/util/convert.php \
    -i Helvetica.afm -t Core -o resources/fonts
```

`-t Core` is the important part: `-t Type1` demands a binary `.pfb`, which the mirror does not
carry for the core family. For PR 7 the package should ship the generated JSON under
`resources/fonts/` and define `K_PATH_FONTS` from the service provider, so consumers never
have to know any of this.

## What this does **not** prove

- **No external reader validation.** Checks are structural (PDF object inspection) plus one
  live TSA round-trip. Adobe Reader / ITI Validar conformance is still PR 7 work.
- **OCSP and CRL were disabled** — the self-signed test certificate has neither an OCSP
  responder nor a CRL distribution point. Only certificate embedding in the DSS is exercised.
- **B-LTA was not exercised** end to end, only the B-LT DSS material.
- **Signing an existing external PDF** — not covered here; that is PoC 0b's territory.
