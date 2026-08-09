# IMPORTANT

This version is **not** compatible with non-Laravel applications.

Version 2 resolves everything through Laravel's service container: the five contracts (`A1PdfSign`, `PdfSigner`, `SealRenderer`, `SignatureValidator`, `CertificateReader`) are bound by the service provider, and configuration is read through Laravel's config repository. There is no standalone entry point, and there is no plan to add one.

If you need this package outside Laravel, use version [0.x](/docs/0.x/not-laravel-or-lumen). Be aware of what that means: 0.x predates the rewrite, so it carries the signing behaviour that discards earlier signatures, and the validation that never checked whether a signature matched the document.
