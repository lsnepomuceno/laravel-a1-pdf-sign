# 0030: Signing a document that is encrypted

**Status:** implemented, for AES. RC4 is refused, deliberately.

## Context

[0014](0014-refuse-encrypted-documents.md) refused encrypted documents, and it
was right to. The cross-reference table is not encrypted, so reading gets far
enough to look successful while the strings and streams around it are
unreadable, and the plaintext revision written beside them produces a file whose
new objects no reader can decrypt. There is no exception, and the damage shows
up when somebody opens the file.

That record also wrote down what the real fix would be:

> Signing an encrypted document properly means implementing the standard
> security handler: decrypting to read, and encrypting the appended revision
> under the same key so the whole file stays consistent. That is a real feature
> with a real surface, including the question of where the document password
> comes from and how it is kept out of stack traces.

**Password-protected documents arrive.** The refusal is a clear error rather
than a corruption, which was the point, but it is still a wall.

## Decision

Implement the standard security handler, for AES.

```php
A1PdfSign::newSignature()
    ->certificate($pfxPath, $certificatePassword)
    ->pdf($path, 'the document password')
    ->sign();
```

The document's password and the certificate's are different things and are
passed separately: one opens the file, the other unlocks the key that signs it.
Both carry `#[\SensitiveParameter]`, so neither appears in a stack trace.

### The key is derived and then checked

`Signing\Encryption\StandardSecurityHandler` implements ISO 32000-1 §7.6.4.3 for
revisions 2 to 4 and ISO 32000-2's algorithms 2.A and 2.B for revisions 5 and 6.
It tries the user password and then the owner password, and **verifies the
result against the check value the document carries**.

That verification is the part worth insisting on. A wrong password derives a key
that encrypts to noise, and noise appended beside a document is exactly the
silent corruption 0014 refused to produce. Checking turns it back into an error
message.

### RC4 is refused, and that is a decision

The package reads enough RC4 to recognise it and says so. It will not write it.

Signing an RC4 document means encrypting the appended revision with RC4, which
would mean this package weakening a file in order to sign it. qpdf refuses to
*write* RC4 without `--allow-weak-crypto`; refusing to write it here is the same
judgement.

RC4 does appear once, in the handler, to recompute the `/U` check value for
revision 4's password check (algorithm 6). It is written out rather than taken
from OpenSSL because OpenSSL 3 moved RC4 to the legacy provider and a password
check should not depend on how the host configured that. `tests/ArchTest.php`
exempts that one class by name and explains why.

### The trailer has to repeat `/Encrypt`

The defect that made this look almost-working: the appended revision's trailer
omitted `/Encrypt`, so a reader arriving at the newest trailer concluded the
document was not encrypted, stopped decrypting, and every stream written before
it inflated to nothing. qpdf reported *"incorrect header check"* on three
objects; a user would report that the file was broken.

§7.5.5 says every trailer carries it. It does now.

### `/Contents` is the one thing not encrypted

§7.6.2 excludes the signature's `/Contents` from encryption, because it is the
signature over the bytes rather than content of the document. Encrypting it
produces a file that decrypts perfectly and verifies nowhere.

Everything else the revision writes is encrypted: the field name, the signing
time, the seal's image, its soft mask, its colour profile and its form XObject.
`Signing\Encryption\ObjectCipher` is a null object rather than a branch at each
of those nine sites, because nine branches are nine chances to forget one, and
a forgotten one is a stream no reader can decode inside an otherwise valid
signature.

## What is refused, and why each is named

| | |
|---|---|
| RC4 content | Signing would mean writing RC4 back into the document |
| A security handler other than the standard one | Its key comes from somewhere this package cannot reach, by definition |
| An encrypted document packed into object streams | The streams holding the objects are encrypted too, so the catalog cannot be read without decrypting on the way in as well. Reachable, not done |
| `pades-b-lt` and above, while encrypted | They append a security store and an archive timestamp built by tc-lib, whose streams would go in unencrypted |

The last two are boundaries rather than principles, and both refuse with a
message naming the reason. Silence is what 0014 exists to prevent.

## Verification

The fixtures are **qpdf's output**, and qpdf reads the signed result back. That
direction is the one that matters: this package's reader agreeing with this
package's writer would prove nothing about either.

`tests/EncryptedDocumentTest.php` gates that a wrong password is refused, that
RC4 is refused, that qpdf finds no complaint about a signed AES-128 or AES-256
document, that `/Contents` survives as readable DER, that the field name does
not survive as readable text, and that a sealed encrypted document is still
clean.

## Consequences

- **0014's refusal becomes conditional**, and its reasoning survives intact: a
  document this package cannot key is still refused rather than corrupted.
- `Contracts\PdfSigner::sign()` and `PendingSignature::pdf()` take a further
  argument, appended with a default.
- `DocumentReader::read()` takes a password, and `DocumentInfo` carries the key
  once one has opened the document.
- `Enums\EncryptionAlgorithm` is new.
- **Nothing changes for an unencrypted document.** The cipher is inactive, and
  every writer emits the same bytes it did before.

## Alternatives rejected

| | Why not |
|---|---|
| Keep refusing | 0014 said the refusal was a placeholder for this, and said so in writing |
| Support RC4 as well | Writing RC4 to sign a document is weakening it, and this package would be the one doing it |
| Decrypt the document and sign it unencrypted | It silently removes the protection its author chose |
| Take the password from configuration | It is a property of one document, not of the installation |
| Trust the password and skip the check | A wrong one corrupts the file quietly, which is the exact failure 0014 exists to prevent |
| Branch on `isEncrypted()` at each writer | Nine branches, nine chances to forget one |
