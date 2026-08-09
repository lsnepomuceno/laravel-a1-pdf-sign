# 0003: Temporary files live outside the package, with guaranteed cleanup

**Status:** accepted, implemented.

## Context

v1 wrote its temporary files to `src/Temp/`, inside the consuming application's
`vendor/`. That required `vendor/` to be writable, behaved differently per
environment, and, because `File::delete()` always ran on the happy path only, it
left files behind on every exception path. One of those files was a decrypted
private key.

## Decision

`Support\TemporaryFile` writes to `sys_get_temp_dir()`, or to the path
configured as `temp_path`. Never inside the package. `src/Temp/` ceases to
exist.

Cleanup happens in a `finally` block, with `__destruct()` as a backstop for the
paths a `finally` cannot cover.

## Consequences

- `vendor/` no longer needs to be writable.
- The configured path is nullable and falls back to the system temporary
  directory, so a host application only sets it when it has a reason to.
- `A1PdfSign::tempPath()` is the public accessor, replacing v1's `a1TempDir()`.
