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

Nothing is currently proposed and unbuilt. The four that were, 0009, 0010, 0012
and 0013, all shipped in 2.2, and each carries the measurement that decided its
shape rather than only the shape.

**0012 is the one to read before trusting it.** It is implemented and its
verification is deliberately incomplete: `pdfsig` does not surface `/DocMDP`, so
whether a reader *enforces* a certification is untested here. The record says so
rather than rounding up.

Rules that break the product when violated are not decisions and do not live
here. They are in [the invariants](../spec/invariants.md).
