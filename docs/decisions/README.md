# Decisions

One file per decision, numbered. The number is the identifier: it is what code
cites, so it never changes and is never reused.

Each records the context that forced a choice, the alternatives weighed, and an
outcome section when what shipped differed from what was decided. That last part
is the point: a decision record whose outcome is never written back is how a
document drifts away from the code it describes.

| # | Decision |
|---|---|
| [0001](0001-openssl-native-with-cli-fallback.md) | Read certificates through `ext-openssl`, keep the CLI as a fallback |
| [0002](0002-asn1-parsed-in-package.md) | Parse the CMS in-package, by declared length |
| [0003](0003-temporary-files-outside-the-package.md) | Temporary files live outside the package, with guaranteed cleanup |
| [0004](0004-in-memory-seal.md) | The seal is rendered in memory |
| [0005](0005-php-and-laravel-floor.md) | PHP 8.4 and Laravel 13 as the floor |
| [0006](0006-incremental-revision.md) | Sign by appending a revision, written in-package |
| [0007](0007-pem-second-entry-one-pipeline.md) | PEM as a second entry point onto one pipeline |
| [0008](0008-exceptions-name-the-real-fault.md) | Exceptions name the fault that actually occurred |
| [0009](0009-cross-reference-streams.md) | Cross-reference streams |
| [0010](0010-validation-consumes-what-signing-writes.md) | Validation consumes the material signing writes |
| [0011](0011-signing-time-and-certificate-validity.md) | The report carries signing time and certificate validity |
| [0012](0012-certification-signatures.md) | Certification signatures and DocMDP |
| [0013](0013-signing-into-an-existing-field.md) | Signing into a field the document already carries |
| [0014](0014-refuse-encrypted-documents.md) | Encrypted documents are refused, not signed badly |
| [0015](0015-object-streams.md) | Objects packed into object streams are read, and written back uncompressed |
| [0016](0016-trust-is-the-applications-policy.md) | Trust is the application's policy, and its verification is ours |
| [0017](0017-the-seal-goes-where-it-was-asked-for.md) | The seal goes where it was asked for |
| [0018](0018-prefer-the-platforms-own-constructs.md) | Prefer the platform's own constructs: Laravel's helpers, and enums over class constants |
| [0019](0019-validation-reads-what-it-writes.md) | Validation reads what it writes, one level down |
| [0020](0020-decode-the-filters-documents-use.md) | Decode the filters documents actually use |
| [0021](0021-locking-fields-and-honouring-locks.md) | Locking fields, and honouring the locks already there |
| [0022](0022-the-archive-timestamp-is-a-chain.md) | The archive timestamp is a chain, not a state |
| [0023](0023-a-seal-that-can-be-transparent.md) | A seal that can be transparent, and say what the caller wants |
| [0024](0024-revocation-is-evaluated-not-counted.md) | Revocation is evaluated, not counted |
| [0025](0025-what-signing-does-to-pdf-a.md) | What signing does to PDF/A, measured |
| [0026](0026-verification-tools-are-instruments.md) | The verification tools are instruments, and nothing skips |
| [0027](0027-the-transport-is-a-seam.md) | The transport is a seam, so the profiles can be gated |
| [0028](0028-the-seal-carries-its-own-colour-space.md) | The seal carries its own colour space, built rather than vendored |
| [0029](0029-the-identity-a-brazilian-signer-is-known-by.md) | The identity a Brazilian signer is known by |
| [0030](0030-signing-a-document-that-is-encrypted.md) | Signing a document that is encrypted |
| [0031](0031-certification-verified-by-a-reader.md) | Certification is verified by a reader that enforces it |
| [0032](0032-what-signing-does-to-pdf-ua.md) | What signing does to PDF/UA, measured |
| [0033](0033-the-seal-honours-page-rotation.md) | The seal honours the page's rotation |
| [0034](0034-signing-owns-the-document.md) | Signing takes ownership of the document |
| [0035](0035-the-audit-trail-is-opt-in.md) | The audit trail is opt-in, and its context is an allowlist |

Nothing is currently proposed and unbuilt. The four that were, 0009, 0010, 0012
and 0013, all shipped in 2.2, and each carries the measurement that decided its
shape rather than only the shape.

**0012 carried a caveat for two releases, and it is closed.** Its verification
was deliberately incomplete, because `pdfsig` does not surface `/DocMDP` and no
reader the project had would say whether a certification is *enforced*.
[0031](0031-certification-verified-by-a-reader.md) found one that does, pyHanko,
and made it a gate: a certified document modified beyond its level is now
reported as violating its policy on every run.

Rules that break the product when violated are not decisions and do not live
here. They are in [the invariants](../spec/invariants.md).
