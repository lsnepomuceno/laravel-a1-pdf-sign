# Disks and uploads

**This is what the wrapper buys that the standalone package cannot have.**

signet-pdf reads a path, a stream or a string, and all three assume the bytes
are already reachable from this machine. A Laravel application's documents
commonly are not: they are on S3, and signing one meant downloading it to a
temporary file, signing that, and uploading the result.

```php
$path = A1PdfSign::newSignature()
    ->certificate($pfx, $password)
    ->from(A1PdfSign::fromDisk('s3', 'contracts/deal.pdf'))
    ->sign()
    ->writeTo(A1PdfSign::toDisk('s3', 'contracts/deal-signed.pdf'));
```

`$path` is what the destination wrote, so it goes straight into a column.

## Naming the output, or not

`toDisk()` with no path keeps the name the document carries, which is the
original with `_signed` appended: `deal.pdf` becomes `deal_signed.pdf`. The
engine names it that way rather than overwriting the input, which is worth
knowing before a scheduled job discovers it.

## From a request

```php
A1PdfSign::newSignature()
    ->certificate($pfx, $password)
    ->from(A1PdfSign::fromUpload($request->file('contract')))
    ->sign();
```

The upload is read where it already is rather than moved into a temporary file
first. Signing needs the bytes and not a path, and every extra copy of a
document is another place it can be left behind.

`signFromUpload()` does the same for the **certificate** arriving in a request,
which is the other half of the same problem.

## What fails, and how

A disk answers a missing file with `null` and a refused write with `false`.
Both are turned into a `FileNotFoundException` **naming the path**, rather than
being passed on: left alone they arrive at signing as "could not parse", which
sends whoever is debugging to look at the PDF instead of at the path.

The write side matters more than the read side. A signature that was produced
and then silently not stored leaves the caller believing it has a signed
document.

## A document too large to hold

All of the above read the whole document into memory. For one larger than that,
the engine takes a stream:
[signing a document larger than memory](https://github.com/lsnepomuceno/signet-pdf/blob/main/docs/guide/signing.md).

## Testing it

`Storage::fake('s3')` covers both directions, with no credentials and no
network.
