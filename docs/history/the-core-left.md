# The core left, and what the wrapper turned out to be

Frozen. Written while the 3.0 line was being built, and kept because it answers
a question the code cannot: what the extraction actually cost, as opposed to
what [0039](../decisions/0039-the-core-lives-in-signet-pdf.md) predicted.

## What moved

25,378 lines of deletions against 1,400 insertions, in one pull request. Nine
directories: `Signing`, `Validation`, `Certificates`, `Seal`, `Support`,
`Data`, `Enums`, `Exceptions`, `Testing`, plus five contracts, their tests,
`samples/` and `poc/`.

What is left is eight files' worth of wiring, three adapters, three IO classes,
six commands and a fake.

## The plan was wrong about one thing, and a command said so

The sequencing said the adapters would be written against signet while the old
core was still present, so `main` stayed installable throughout. That is not
possible, and `composer require --dry-run` is what established it:

```
- lsnepomuceno/signet-pdf 3.0.0 requires intervention/image ^4.3 -> found intervention/image[4.3.0, 4.3.1, 4.3.2]
  but it conflicts with your root composer.json require (^3.11).
```

The seal here rendered on Intervention 3 and the seal there renders on 4, and
`tecnickcom/tc-lib-pdf-sign` was the same shape one version apart. **Two
packages that cannot be installed together cannot be adapted one to the other
incrementally.** The answer was an integration branch, `feat/v3`, with each
issue landing as a pull request against it and `main` receiving one at the end.

Worth keeping because the same shape recurs: an extraction that changes a
shared dependency's major version cannot be done side by side, whatever the
plan says.

## A red test made the argument better than the record did

0039 argues that the process adapter is not a convenience but invariant 8
surviving the move. The evidence arrived on its own:
`tests/Console/CheckEnvironmentTest.php` asserts that `Process::fake()`
intercepts the `openssl` shell-out, and the moment the engine's own
`SymfonyProcessRunner` was resolved, it failed.

A class built inline cannot be faked. The test had been written a release
earlier for an unrelated reason, and it caught the exact regression the
decision record had only been able to describe.

## What the wrapper needed that the record did not anticipate

- **`ReaderFactory` built by hand.** `Signet::certificateReader()` takes no
  arguments, because the standalone package configures the choice once. Here
  the per-call `$usePathEnv` override is published API, so the manager builds
  the factory rather than using the accessor.
- **The fake had to replace the engine, not a binding.** `A1PdfSignFake`
  rebound `PdfSigner` in 2.x and that was enough. It is not any more: the
  engine resolves its own signer, so swapping the contract alone would leave
  `newSignature()->…->sign()` reaching the real one.
- **Type coverage was a CI-only gate.** It had been for a release, and the
  first pull request to trip it was green locally and red in CI. It is in
  `composer check` now.

## What it cost a consumer

Every import. Nothing else: the facade, its methods, their arguments, the
config keys, the artisan commands and the encryption envelope are unchanged.
`UPGRADE.md` carries the table, and `sed` does most of the work.
