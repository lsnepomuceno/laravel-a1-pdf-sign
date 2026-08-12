# 0034: Signing takes ownership of the document

**Status:** implemented, with a cost to the published contract that is the whole
point of this record.

## Context

Signing a 200 MB document needed over 620 MB of PHP memory. Architectural plans
of that size are an ordinary input, not an edge case, and no host runs a
`memory_limit` that high by default.

Measured per stage on a 25 MB document, the peak is three strings alive at once:

| | |
|---|---|
| the original bytes | held by `Signing\PendingSignature` for the whole call |
| the document with its new revision | what the writer returns |
| the signable span | the document minus its `/Contents` hole, built to be hashed |

`peak ≈ 20 MB + 3 × file`, linear from 10 MB to 200 MB.

Two of those three are hard to remove:

- **The span** exists because `Com\Tecnick\Pdf\Sign\Signer::sign()` takes the
  covered bytes as a string and hashes them itself. Its only use of them is
  `hash($algorithm, $data, true)`, so a digest computed incrementally would do,
  but the library exposes no entry point that accepts one. Removing this copy
  means upstream work.
- **The document plus its revision** is what is being produced. It has to exist.

**The original bytes are the removable one**, and they were being held for no
reason: every guard that reads them runs before the revision is appended.

## Decision

**`Contracts\PdfSigner::sign()` takes the document by reference, and empties it
once the revision has been appended.**

The builder passes its own property, so the signer holds the only reference and
can release it. Refcounting is why nothing weaker works: a local in the caller
and a parameter in the callee are the *same* string, so nulling the property
before the call frees nothing while the caller still has a name for it. Only the
callee writing through a reference drops the last owner.

Measured, on a 25 MB document: peak **95.0 MB before, 70.0 MB after**. The shape
becomes `20 MB + 2 × file`, so a 200 MB plan needs about 420 MB rather than 620.

## What it costs, stated plainly

**This is a breaking change to a published contract.** PHP cannot pass an
expression by reference, so a consumer calling the contract directly must now
pass a variable:

```php
// no longer valid
$signer->sign(Files::read($path), $certificate, $info);

// valid
$contents = Files::read($path);
$signer->sign($contents, $certificate, $info);
```

Two tests in this repository were written the first way, which is evidence that
it is the natural form rather than an unusual one.

**The documented API is unaffected.** `A1PdfSign::newSignature()->…->sign()` and
the one-shot helpers pass a property and never an expression, so every example in
the README and on the documentation site keeps working unchanged.

**The builder becomes re-readable rather than reusable in place.**
`PendingSignature` remembers the path it read from and loads the bytes again if
the same builder signs twice. A builder given bytes directly through
`pdfContents()` has nothing to re-read, so signing twice with it raises and says
what to do.

## Consequences

- `peak ≈ 20 MB + 2 × file`, which is the floor reachable without either
  upstream work on the digest or streaming the output.
- `Support\Bytes::overwrite()` writes the two fixed-width spans, `/ByteRange`
  and the `/Contents` payload, over the document in place. `substr_replace()`
  built a whole new string to change twenty characters; on a 25 MB document that
  measured 52 MB against 27 MB. Both replacements are fixed width by
  construction, which is the reason the placeholders are padded at all.
- **This is not the end of the work.** Getting below 2 × means streaming, which
  changes `Data\SignedPdf` and the entry points, and that is a larger decision
  than this one.

## Alternatives rejected

| | Why not |
|---|---|
| Leave the contract alone and accept 3 × | 620 MB for a document a customer will actually send |
| Null the property before calling | Frees nothing: the caller's local and the callee's parameter are the same string |
| Fork or vendor the CMS builder to accept a digest | A dependency this package deliberately does not own |
| Stream the input now | The right answer, and a much larger change: it moves `SignedPdf`, `save()`, `download()` and both entry points |
