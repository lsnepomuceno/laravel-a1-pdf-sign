# 0036: The signed artefacts are reproducible, and their coherence is a gate

**Status:** implemented.

## Context

`poc/sign-samples.php` minted a **fresh certificate on every run**, and
`samples/README.md` said "the script writes to `.output/`; copy what you need
over the files here".

Those two together produced a failure with no test to catch it. Regenerating
`samples/` replaced the certificate, eleven PDFs and the certificate were copied
over, and a twelfth committed artefact outside `samples/` that depended on the
same certificate was not: `tests/Resources/foreign-signed.pdf` stayed signed by
fingerprint `163d4764…` while the repository held `5ab3345c…`.

**Nothing failed.** `ForeignSignatureTest` reads the document rather than the
anchor, so it stayed green while the file's own docblock, which names the
certificate that signed it, had become false. It was found by comparing
fingerprints by hand.

It also broke something two steps away without failing.
`CertificationEnforcementTest` takes its trust anchor from the sample
certificate, so the tampered fixture would have gone on passing **for the wrong
reason**: an untrusted signature is INVALID too, and the test asserting INVALID
cannot tell the two apart.

## Decision

### The identity is committed, not minted

`sign-samples.php` signs with `samples/certificate.pfx` when it exists, and mints
one only to bootstrap a checkout that has none. Regenerating the documents then
cannot invalidate anything downstream, which removes the failure at its source
rather than detecting it afterwards.

**The expiry becomes a property of the repository**, and that is the trade.
A bootstrap certificate is minted with ten years of validity, and the day the
committed one expires is the day every signed artefact is regenerated together.
`tests/ArtefactCoherenceTest.php` asserts it has not expired, so that day
arrives as a red test rather than as a puzzling verdict.

### One command writes every artefact

`--write` copies everything committed, in `samples/` and in `tests/Resources/`,
or nothing. The manual copy is where the error entered: nobody copies a file
they did not know depended on the certificate.

Two fixtures are produced by pyHanko rather than by this package, so the script
names them and points at the tests that carry their commands, instead of
pretending to regenerate them.

### The gate is identity, never bytes

Signed output is **not reproducible**: the signing time and the padding differ
per run, so "regenerate and compare" can never be green twice.

`tests/ArtefactCoherenceTest.php` asserts instead that every committed signed
artefact carries the certificate the repository holds, by fingerprinting the DER
embedded in each signature. Not the serial and not the common name: a throwaway
bundle has serial 0 and the same subject as every other one ever generated, so
neither can tell two of them apart.

## Consequences

- Twelve artefacts checked, and a thirteenth test asserts the list is not empty,
  since a glob that silently returns nothing would make the gate pass having
  checked nothing.
- **Regenerating on every build stays out of scope.** `samples/` exists to be
  opened in real readers, and a file that changes on every CI run has no stable
  identity for anybody to check and report on.

## Alternatives rejected

| | Why not |
|---|---|
| Compare bytes against a committed expectation | Signed output is not reproducible; the gate could never be green twice |
| Keep minting per run and check afterwards | Detects the failure instead of removing it |
| Regenerate in CI | The samples exist to sit still and be opened by a person |
| Check the serial or the subject | Identical across every throwaway certificate this package generates |
