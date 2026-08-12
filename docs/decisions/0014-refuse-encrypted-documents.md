# 0014: Encrypted documents are refused, not signed badly

**Status:** accepted, implemented, and now **conditional**. [0030](0030-signing-a-document-that-is-encrypted.md) implemented the security handler this record described as the real fix, so an AES-encrypted document opens with its password instead of being refused. Everything below still holds for a document this package cannot key: RC4 content, a non-standard handler, or an encrypted document packed into object streams.

## Context

Nothing in `src/Signing/` looked at `/Encrypt`. A password-protected or
permissions-protected PDF was read as ordinary bytes and appended to.

That fails in the worst available way. The reader walks the cross-reference
table, which is not encrypted, so it gets far enough to look like it worked.
Strings and streams inside the objects are encrypted, and the revision written
beside them is not, so the output is a document whose new objects a reader
cannot decrypt with the rest. There is no exception, and the damage shows up
when somebody opens the file.

## Decision

Refuse. `DocumentReader` raises when the trailer carries `/Encrypt`, naming the
reason.

Signing an encrypted document properly means implementing the standard security
handler: decrypting to read, and encrypting the appended revision under the same
key so the whole file stays consistent. That is a real feature with a real
surface, including the question of where the document password comes from and
how it is kept out of stack traces. It is not this decision, and pretending a
guard is a substitute for it would be worse than the guard.

## Consequences

- A previously silent corruption becomes a clear error, which is a behaviour
  change for anyone who was feeding encrypted documents in and not checking the
  result.
- Support for encrypted documents, if it is ever wanted, starts from a stated
  refusal rather than from an undefined behaviour, which is a better place to
  start.
