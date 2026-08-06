# Signed samples

One signed PDF per signature profile, plus a document carrying six signatures.
They exist so a change to the signing engine can be checked against real
readers — Adobe Reader, ITI Validar, poppler's `pdfsig` — and not only against
this package's own validator, which shares its assumptions with the code it
validates.

Regenerate them after any change to `src/Signing/`:

```bash
docker compose -f .docker/compose.yaml run --rm php php poc/sign-samples.php
```

The script writes to `.output/`; copy what you need over the files here.

## The certificate is untrusted, and that is expected

`certificate.pfx` is the throwaway self-signed certificate the test suite
generates. Its password is:

```
example's password with special chars: $ & * ? " '
```

**Every reader will report the signer as untrusted.** That is the certificate's
provenance, not the signature's integrity: it is self-signed and chains to
nothing. Everything else validates normally — document hash, sub-filter,
timestamp token, and the coverage of each signature.

To make Adobe Reader report the signer as trusted, import `certificate.pfx` and
add it as a trusted identity for signature validation. ITI Validar will always
reject it, since it only trusts the ICP-Brasil chain; testing there needs a real
ICP-Brasil certificate.

## The files

| File | What it carries |
|---|---|
| `legacy.pdf` | `/SubFilter adbe.pkcs7.detached` — ISO 32000-1, widest reader support |
| `pades-b-b.pdf` | PAdES B-B — `ETSI.CAdES.detached` with the ESS `signing-certificate-v2` attribute |
| `pades-b-t.pdf` | B-B plus an RFC 3161 token from freetsa.org |
| `pades-b-lt.pdf` | B-T plus a Document Security Store |
| `pades-b-lta.pdf` | B-LT plus an archive timestamp — a second `/ByteRange`, of type `ETSI.RFC3161`, covering the whole file |
| `six-signatures.pdf` | Six signatures on one document |

## What `six-signatures.pdf` proves

This is the case [TCPDF#430](https://github.com/tecnickcom/TCPDF/issues/430)
could not produce. In v1 each new signature rebuilt the document through FPDI
and discarded the previous one; here each signature is an appended revision, so
all six coexist.

Verified with poppler `pdfsig` 25.12 — all six report *Signature is Valid*, with
progressive coverage:

```
#1  [0 - 9190],  [25576 - 26301]
#2  [0 - 26430], [42816 - 43556]
#3  [0 - 43685], [60071 - 60825]
#4  [0 - 60954], [77340 - 78108]
#5  [0 - 78237], [94623 - 95405]
#6  [0 - 95534], [111920 - 112717]   <- "Total document signed"
```

Each signature covers the file as it stood when that signature was made, which
is why `pdfsig` reports "Not total document signed" for #1 to #5. That is
correct, and it is how a reader knows what each signer actually saw.

Checking a sample yourself:

```bash
pdfsig samples/six-signatures.pdf
```
