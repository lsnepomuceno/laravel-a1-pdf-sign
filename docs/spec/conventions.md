# Conventions

Rules about how the code is written, as opposed to what it must do. The rules
that break the product live in [the invariants](invariants.md); these break the
codebase slowly instead, which is why they are written down rather than left to
whoever reviews.

Each is checked at review. Where a rule can be checked by a machine, it is, and
that is noted.

---

# 1. Laravel first

**This package is a Laravel package. Before writing a helper, check whether the
framework already has it, and use that.**

The package requires `illuminate/support`, `illuminate/http`, `illuminate/process`
and `illuminate/filesystem` outright. Everything in them is already installed, already
tested, already documented, and already familiar to the person reading the code.
A private reimplementation of any of it is code this project has to maintain,
test and explain, in exchange for nothing.

This is a rule, not a preference. It is checked at review, and part of it is
checked by `tests/ArchTest.php`.

---

## The rule

1. **Look in the framework first.** `Illuminate\Support\Str`, `Arr`,
   `Collection`, `Facades\File`, `Facades\Process`, `Facades\Http`,
   `Facades\Config`, `Facades\Cache`, the `Illuminate\Contracts\*` interfaces.
2. **If it exists there, use it**, even when the native call is two characters
   shorter.
3. **If it does not, write it**, put it in `src/Support/`, and say in the
   docblock what the framework does not provide. A helper whose docblock cannot
   answer "why is this not `Str::something`" is a helper that should not exist.

Exceptions are below and they are narrow. Everything not listed there follows
the rule.

---

## Reach for

| Instead of | Use | Why |
|---|---|---|
| `file_get_contents`, `file_put_contents` | `Support\Files::read()`, `File::put()` | `Files::read()` exists because both the native call and `File::get()` return `false`, and that `false` reaching a `string` parameter was this package's most common typing defect |
| `is_dir`, `mkdir`, `unlink`, `glob` | `File::isDirectory()`, `File::makeDirectory()`, `File::delete()`, `File::glob()` | one filesystem abstraction, fakeable in a host application's tests |
| `uniqid`, `random_bytes` for a name | `Str::orderedUuid()`, `Str::random()` | already how `Support\TemporaryFile` names its files |
| `exec`, `shell_exec`, `proc_open` | `Support\ProcessRunner` on `Illuminate\Process` | invariant 8, and `Process::fake()` in a consuming application |
| `curl_*`, `stream_context_create` + `file_get_contents` | `Illuminate\Support\Facades\Http` | timeouts, retries and `Http::fake()`, instead of a hand-rolled stream context |
| `array_map` / `array_filter` / `array_merge` chained over one value | `collect()` | one pipeline instead of three nested calls, when it genuinely reads better |
| a hand-written `get($array, 'a.b.c')` | `Arr::get()` | |
| reading config with a cast and a default | `Illuminate\Contracts\Config\Repository`, injected | already how the package reads every configuration key |
| a hand-rolled `toArray()` on a value object | `Illuminate\Contracts\Support\Arrayable` | `Data\BaseData` already implements it |

## Do not reach for

These are the narrow exceptions, and each is load-bearing.

| Keep the native call | Why |
|---|---|
| **`substr`, `strlen`, `strpos`, `str_replace` on PDF or DER bytes** | **`Str::substr()` and `Str::length()` are multibyte-aware.** Running them over a PDF or a CMS reinterprets binary as UTF-8 and returns the wrong offsets, which in this package means a corrupted signature. Byte work uses byte functions, always |
| `preg_match`, `preg_match_all` | `Str::match()` returns the match and throws the offsets away, and offsets are what the incremental writer is built on. `Str::isMatch()` is fine where only the boolean is wanted |
| `openssl_*` | the framework wraps none of it |
| `pack`, `unpack`, `bin2hex`, `hex2bin`, `gzuncompress` | no framework equivalent, and all byte-exact |
| `hash(..., binary: true)` | `Hash::` is password hashing, a different thing entirely |

The first row is the one that matters. If a change swaps a byte-level `substr`
for `Str::substr`, it will pass every test in this suite on ASCII fixtures and
corrupt real documents in production.

*Enforced by* `tests/ArchTest.php`, which fails when `Illuminate\Support\Str` is
used inside `src/Signing` or `src/Validation` at all: those namespaces are where
the byte work lives, and the rule is easier to keep as "not here" than as "here,
but only these methods".

---

## Known outstanding

`Signing\Cades\HttpTransport` builds its own `stream_context_create` and calls
`file_get_contents` for the TSA, OCSP and CRL requests. `Http::` is the right
tool and `guzzlehttp/guzzle` is already in the tree, so this is a gap in the
rule rather than an exception to it. It is called out here rather than left for
someone to find, and moving it also makes the network surface fakeable, which is
the same argument that put `ProcessRunner` on `Illuminate\Process`.

Rationale and alternatives: [0018](../decisions/0018-prefer-the-platforms-own-constructs.md).

---

# 2. Enums, not class constants

**A closed set of values is an enum.** A class constant is for the case where
exactly one value can ever exist, and for nothing else.

PHP has had enums since 8.1 and this package's floor is 8.4, so a set of related
constants is a type the language will check for you that has been written as a
set of integers it will not.

| Write | Instead of |
|---|---|
| `enum SignatureProfile: string` | `const PADES_B_B = 'pades-b-b'` beside four siblings |
| `enum CertificationLevel: string` | `const NO_CHANGES = 1`, `const FORM_FILLING = 2`, … |
| `enum Asn1Tag: int` | `const SEQUENCE = 0x30`, `const SET = 0x31`, … |

A constant stays a constant when it is a lone fact about the world rather than
one of several choices:

| Legitimate constant | Why |
|---|---|
| `CertificateVault::CIPHER` | one cipher, chosen once |
| `IncrementalSigner::CONTENTS_HEX_LENGTH` | one reserved width |
| `Pem::CERTIFICATE_MARKER` | one string, fixed by RFC 7468 |
| `ByteRangeCalculator::FIELD` | one placeholder shape |
| `LaravelA1PdfSignServiceProvider::CONFIG_PATH` | one path |
| `XrefStreamWriter::WIDTHS` | one column layout, fixed by §7.5.8 |

The test is not "is it private" or "is it an array". It is **"could a second
value of this kind ever be right?"** If yes, it is an enum today, because the
sibling arrives later and arrives as a constant beside the first one.

## Enums that are not configuration may be int-backed

`tests/ArchTest.php` requires enums in `Enums\` to be string-backed, so a
configuration file can name a case in plain text. That reason does not reach an
enum nobody configures, like an ASN.1 tag whose values are fixed by
ISO/IEC 8825-1 and are natural integers. Those are exempt by name in the arch
rule, the way `sha1` is exempt for `SignatureDetails`, rather than by weakening
the rule for every enum.

## Known tension

`Data\SealPlacement::LAST_PAGE` is an `int` sentinel of `-1`, and by this rule it
would be an enum. It was one: `Enums\SealPage` existed and was removed during the
v2 work on the grounds that "the page is one field of a placement, not a concept
with its own behaviour"
([the modernisation record](../history/v2-modernization.md)).

That reasoning predates this rule and is not obviously wrong, and reversing it
now would change the type of a public property. It stays as it is, named here so
the next person finds a decision rather than an oversight.

---

# 3. A docblock documents the thing under it

Two failures, both of which shipped, both now checked by `tests/ArchTest.php`
rather than left to review.

## Never leave two docblocks in a row

```php
/**
 * The signature applied last, which is the only one covering the whole file.
 */
/**
 * The archive timestamps, which are reported separately from signatures.
 */
public function timestamps(): array
```

That is real code from `Data\SignatureReport`. A method was inserted between a
docblock and the method it described, so the first block ended up attached to
the newcomer and `latest()` was left undocumented. **Every tool that reads
docblocks then reports the wrong thing about two methods**, and the diff that
caused it looks like a pure addition.

Found four times across `src/` and `tests/` the day the rule was written.

When adding a method next to an existing one, put the new docblock **above the
new method**, not above the old one. When a docblock and a `@param` block end up
separated, merge them into one block; PHP associates only the last.

## Never leave a `@param` naming a parameter that is gone

The other half of the same problem: the signature moved and the prose did not.
A docblock that documents nothing is a comment nobody reads. A docblock that
documents the wrong thing is worse than no docblock, because it is believed.

## Every file declares strict types

`declare(strict_types=1);` at the top of every PHP file in `src/`, `tests/` and
`config/`. Not optional, and not a preference.

A package that signs documents does arithmetic on byte offsets constantly, and
without it `substr($pdf, "12")` and `str_repeat('0', 8.9)` are coerced in
silence. Both produce a file that is subtly wrong rather than one that fails,
which is the worst outcome available to a signature.

**The blast radius is smaller than it sounds, and worth knowing.** Strict types
are decided by the *calling* file, so a consuming application that does not
declare them keeps its own coercion when it calls this package. What becomes
strict is this package calling itself, and this package calling PHP.

It was switched off deliberately until 2026-08-12: `pint.json` carried
`"declare_strict_types": false` and not one of the 169 files declared it.
Turning it on changed no behaviour, and the whole suite passed unmodified,
which says the code was already written as though it were on.

*Enforced by* `pint.json`, which writes the declaration, and by
`tests/ArchTest.php` twice: an arch expectation over `src/`, and a file walk for
`tests/` and `config/`, where arch expectations cannot reach because those files
declare no classes. `poc/` is out of scope, as it is for Pint and PHPStan.

## Never cite a file that does not exist, and write it first

A comment, docblock or document may only name a path that resolves **at the
moment it is written**. Not "will exist when the record is written up", not
"exists on the branch that has not landed": now.

**The record comes first.** When a change wants a decision record or a
specification section, that file is created before the code referring to it, in
the same change and earlier in it. The reverse order produces a reference to
something nobody wrote, and the code then documents an argument that was never
made.

This is not hypothetical and it is not other people's mistake. A comment in
`Signing\IncrementalSigner` was written citing a decision record numbered 0034,
about holding the document once, while the fix it described was still being
measured. The record was never written, the reference stayed, and the only
reason it did not ship is that `tests/SpecTest.php` refused the commit.

**The first draft of this very section quoted that path in full, to illustrate
the rule, and the gate refused that too.** Which is the right outcome: a scanner
cannot tell an example of a bad reference from a bad reference, and a rule whose
own text has to be exempted is a rule with a hole in it. Describe the missing
file; do not spell it.

*Enforced by* `tests/SpecTest.php`, which walks every `.php`, `.md`, `.yml` and
`.yaml` file in the package and resolves every documentation path any of them
cites. It is a gate rather than a review point, and it is the reason this rule
can be stated so flatly.

**What it does not catch**: a comment naming a class, method or constant that no
longer exists. Paths are checked; symbols are not.

## What is deliberately not checked

Whether the prose is *true*. No tool can, which is why the rules above are
narrow: they catch the failures that are mechanical, and leave the rest where it
belongs, with whoever changed the code.
