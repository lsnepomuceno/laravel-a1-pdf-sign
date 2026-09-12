# Upgrading

The full notes, every version, are in [UPGRADE.md](/releases/upgrade). This
page is the short version of the one that matters.

## 2.x to 3.0

**Every import changes. Nothing else has to.**

```bash
grep -rl 'LSNepomuceno\\LaravelA1PdfSign' app/ | xargs sed -i \
    -e 's#LaravelA1PdfSign\\\(Data\|Enums\|Exceptions\|Validation\|Signing\|Certificates\|Seal\|Support\)\\#Signet\\\1\\#g'
```

The facade, its methods, their arguments, every config key, the artisan
commands, the fake and the encryption envelope are what they were. Stored
certificates open without being re-encrypted.

### What the regex does not catch

| 2.x | 3.0 |
|---|---|
| `Data\IcpBrasilReport` | `IcpBrasil\Data\Report` |
| `Data\IcpBrasilIdentity` | `IcpBrasil\Data\Identity` |
| `Enums\IcpBrasilCertificateType` | `IcpBrasil\Enums\CertificateType` |
| `Enums\IcpBrasilFinding` | `IcpBrasil\Enums\Finding` |
| `Exceptions\A1PdfSignException` | `Exceptions\SignetException` |
| `EncryptedCertificate::$hashKey` | `->hash` |
| `->pdfFromDisk('s3', $path)` | `->from(A1PdfSign::fromDisk('s3', $path))` |

### Two things fail at install rather than at runtime

- **PHP 8.4.1**, up from 8.4.
- **`intervention/image ^4`**, up from `^3.11`. An application pinned to `^3`
  cannot install 3.0.

### Where to report something

A defect in signing, validation, certificates or the seal belongs to
[signet-pdf](https://github.com/lsnepomuceno/signet-pdf/issues). A defect in
the wiring, the config, a command, a disk source or the fake belongs
[here](https://github.com/lsnepomuceno/laravel-a1-pdf-sign/issues).
