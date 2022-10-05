## 💥 Breaking Changes

### Before updating your version 0.x to 1.x, remember to make the necessary adjustments for the correct functioning of the package.

- The namespace of the classes is no longer **LSNepomuceno\LaravelA1PdfSign**, now the classes are located in **LSNepomuceno\LaravelA1PdfSign\Sign**;
- The namespace of the exceptions is no longer **LSNepomuceno\LaravelA1PdfSign\Exception**, now the exceptions are located in **LSNepomuceno\LaravelA1PdfSign\Exceptions**;
- This version has as minimum requirements: **Laravel 9** and **PHP 8.1**;
- It is **NOT POSSIBLE** to use this version with PHP 8.0;
- **NOT FULLY** compatible with **Lumen**, only use Laravel for new projects as described in [**official documentation**](https://lumen.laravel.com/docs/9.x#installation);
- Removed Fluent return types, now we have return types with explicit entities. The impacted methods were:
    - LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert@getCert();
    - LSNepomuceno\LaravelA1PdfSign\Sign\ValidatePdfSignature@from();
    - Helpers encryptCertData() and validatePdfSignature();

<hr>

## Added
- Validation and signature of PDF documents through commands [validate:pdf-sign](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/blob/v1.x-dev/src/Commands/ValidatePdfSignatureCommand.php) and [sign:pdf](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/blob/v1.x-dev/src/Commands/SignPdfCommand.php);
- Full integration with Laravel through ServiceProvider;
- Improved test coverage;

<hr>

## Future Modifications
- Swoole may be adopted;
- Encryption capabilities **WILL BE DECOUPLED** from the **ManageCert class** in upcoming updates;

