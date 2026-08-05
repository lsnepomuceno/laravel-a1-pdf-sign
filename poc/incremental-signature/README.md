# PoC 0b — incremental-revision signing

Spike for PR 0b (see `ARCHITECTURE-V2.md` §3h). **This is not production code** — it exists
to answer a single question before committing to the v2 architecture:

> Can a PDF be signed several times without each new signature destroying the previous one?

**Answer: yes.** 3/3 signatures valid, verified with `openssl smime -verify`.

## Running it

```bash
# host (any PHP >= 8.1, only ext-openssl required)
php poc/incremental-signature/run.php

# container, to double-check on another PHP version
docker compose -f .docker/compose.yaml run --rm \
    -e POC_PDF=/app/tests/Resources/test.pdf php php /app/poc/incremental-signature/run.php
```

## Observed result

Identical on PHP 8.1.34 (host) and 8.4.24 (container):

```
original: 9061 bytes
signature 1: 9061  -> 26313 bytes (+17252) | original prefix intact: YES
signature 2: 26313 -> 43580 bytes (+17267) | original prefix intact: YES
signature 3: 43580 -> 60861 bytes (+17281) | original prefix intact: YES

/Sig objects ... 3
byte ranges .... 3
startxref ...... 4  -> 8605, 26138, 43404, 60685
/Prev chain .... 3  -> 8605, 26138, 43404

signature 1: ByteRange[0 9190  25576 737] covers 9927  of 60861 bytes -> VALID
signature 2: ByteRange[0 26442 42828 752] covers 27194 of 60861 bytes -> VALID
signature 3: ByteRange[0 43709 60095 766] covers 44475 of 60861 bytes -> VALID
```

Each signature covers exactly its own revision — 26313, 43580 and 60861 bytes respectively —
which is the correct ISO 32000-1 semantics. The original document bytes stay untouched
across all three rounds.

## What this proves

| Hypothesis | Status |
|---|---|
| Multiple signatures without overwriting | ✅ confirmed |
| Original bytes preserved | ✅ confirmed — `substr($pdf, 0, $origLen)` identical to the source file |
| Correct `/Prev` chain across revisions | ✅ confirmed |
| Detached PKCS#7 through `ext-openssl`, no shell-out | ✅ confirmed (`openssl_pkcs7_sign`) |
| Certificate generated without the CLI | ✅ confirmed (`openssl_pkey_new` + `openssl_csr_sign`) |

## What this does **not** prove

- **Validation in a real reader.** No Adobe Reader / ITI Validar was used. Verification here
  is cryptographic (`openssl smime -verify`) and structural, not reader-conformance.
- **Cross-reference streams (PDF 1.5+).** The spike handles classic cross-reference tables
  only and **explicitly rejects** xref streams. `test.pdf` is PDF-1.4.
- **Encrypted PDFs**, linearized files, or a complex pre-existing `/AcroForm`.
- **Visual seal.** Signatures are invisible (`/Rect [0 0 0 0]`).
- **LTV and timestamping.** Out of scope here — those come from tc-lib-pdf (§3g).

## Two bugs found while building this

Both are worth keeping as regression tests in the production implementation:

1. **Picking the wrong `/ByteRange`.** An already-signed document contains several of them;
   using the *first* makes the new signature overwrite a previous signature's `/Contents`.
   It must always be the **last** one.
2. **Finding the end of the DER with `rtrim($hex, '0')`.** That cuts legitimate `0x00` bytes
   belonging to the DER itself. The real length comes from the ASN.1 header — see
   `derTruncate()` in `run.php`.

## Provenance

Clean-room implementation written from ISO 32000-1 §7.5.6 (Incremental Updates) and §12.8
(Digital Signatures). No line derived from `ddn/sapp` (LGPL) — see `ARCHITECTURE-V2.md` §3h.
