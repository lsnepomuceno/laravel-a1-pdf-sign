# Bundled fonts

tc-lib-pdf cannot emit any PDF without a font definition — not even a
signature-only document containing no text — and it ships none: upstream builds
them with `make fonts`. A plain `composer install` therefore leaves the package
unable to produce anything. See `ARCHITECTURE-V2.md` §3g.2.

These four files close that gap.

## ⚠️ The constant is not defined globally

`K_PATH_FONTS` is read by **both** tc-lib-pdf and TCPDF 6, with incompatible
formats — JSON here, PHP definition files there. TCPDF 6 only falls back to its
own directory when the constant is *not already defined*, so defining it in the
service provider hijacks its lookup and it fails outright:

```
TCPDF ERROR: Could not include font definition file: helvetica
```

So the constant is defined at the point tc-lib-pdf is used, not globally. The
incremental signer needs neither tc-lib-pdf nor a font — it is byte
manipulation plus ext-openssl — which is why nothing defines it yet.

## Provenance

Metrics for the PDF Core 14 fonts, which are metrics only — no glyph outlines
are embedded, because Core 14 fonts are resolved by the PDF reader.

- **Source:** the Core 14 AFM files Adobe published at
  `https://www.adobe.com/devnet/font/pdfs/Core14_AFMs.zip`, mirrored by
  [`tecnickcom/tc-font-mirror`](https://github.com/tecnickcom/tc-font-mirror).
- **Converted with:** `vendor/tecnickcom/tc-lib-pdf-font/util/convert.php -t Core`

`-t Core` matters: `-t Type1` expects a binary `.pfb`, which the mirror does not
carry for this family.

## Regenerating

```bash
php vendor/tecnickcom/tc-lib-pdf-font/util/convert.php \
    -i Helvetica.afm -t Core -o resources/fonts
```

Only the Helvetica family is bundled. It is what the package itself needs;
applications wanting more can point `K_PATH_FONTS` at their own directory
before the provider boots.
