# ICP-Brasil

The regional layer is signet-pdf's, and it is thorough: certificate types,
the `otherName` extensions a Brazilian certificate carries, the national
registry check, the signature policies and the artefacts the archival policies
require. Its
[ICP-Brasil guide](https://github.com/lsnepomuceno/signet-pdf/blob/main/docs/guide/icp-brasil.md)
is the reference.

What this package adds is reaching it from configuration.

## Declaring a policy

```php
// config/a1-pdf-sign.php
'signature' => [
    'profile' => 'pades-b-lt',
    'policy' => 'ad-rc',
],
```

Every signature then declares AD-RC, and the version used is **the one in
force**. A policy is superseded on a date, and an application that means AD-RC
means the current one, not the one current when the config file was written.

The families map onto profiles:

| Policy | Profile |
|---|---|
| `ad-rb` | `pades-b-b` |
| `ad-rt` | `pades-b-t` |
| `ad-rc` | `pades-b-lt` |
| `ad-ra` | `pades-b-lta` |

A policy that cannot be resolved **refuses at boot** rather than signing
without one. A declaration silently absent is worse than a refusal: the
document looks signed and is not conformant.

## Reading who signed

```php
$report = A1PdfSign::icpBrasil($pfxPath, $password);
```

## What is verified, and by whom

The country's own Verificador accepts what the engine writes, and signet-pdf
holds the evidence. Nothing in this repository measures conformance, because
nothing in it writes a byte.
