# 0001: Read certificates through `ext-openssl`, keep the CLI as a fallback

**Status:** accepted, implemented.
**Supersedes** the v1 behaviour of shelling out for every certificate read.

## Context

v1 converted every PFX by invoking the `openssl` binary. That put the
certificate password on the command line, where `ps` exposed it to any user on
the machine, and wrote the decrypted private key to a file inside the consuming
application's `vendor/`.

`openssl_pkcs12_read()` does the PKCS#12 → PEM conversion natively: no password
in `ps`, no key on disk, and no dependency on the binary being in `PATH`, along
with the whole `$usePathEnv` complication.

**The caveat:** under OpenSSL 3.x, `openssl_pkcs12_read()` **fails** on old PFX
files (RC2/40-bit), and PHP exposes no equivalent to the CLI's `-legacy` flag.
The only alternative fix is enabling the legacy provider in `openssl.cnf`, which
is server configuration, not something a package can do.

## Decision

Native by default; the CLI demoted to a fallback driver behind the
`CertificateReader` contract.

`Certificates\ReaderFactory` chooses between `NativeCertificateReader` and
`OpenSslCliCertificateReader`. The legacy path stays reachable through
configuration (`certificate.legacy`, `certificate.use_path_env`) so the files
that need it keep working, while the majority of cases stop touching disk and
`proc_open` entirely.

## Consequences

- The `openssl` binary is no longer a hard requirement. The test suite does not
  need it at all.
- Two places still reach a process, both through `Support\ProcessRunner`: this
  reader and `Validation\SignatureVerifier`.
- `ReaderFactory` holds the container rather than the `A1PdfSign` contract.
  Resolving the contract there creates a cycle that recurses until the process
  segfaults with no output. See [the invariants](../spec/invariants.md).

## Outcome

`ProcessRunner` was rebuilt on `Illuminate\Process\Factory` rather than
`Symfony\Component\Process`. This does **not** remove Symfony from the tree, since
`illuminate/process` requires `symfony/process`, so the honest framing is not
"one less dependency" but two concrete gains: the direct require becomes an
Illuminate one, matching every other dependency the package declares, and a host
application can `Process::fake()` the call in its own suite, which is impossible
against a class instantiated inline.

The arch rule confining shell-out was widened to cover `Illuminate\Process` too,
so the audit boundary did not move.
