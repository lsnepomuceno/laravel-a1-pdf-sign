# Templates and fields

## Filling a field somebody else placed

```php
foreach (A1PdfSign::signatureFields($template) as $field) {
    $field->name;        // 'SignatureManager'
    $field->isSigned;    // false
    $field->rectangle;   // [30.0, 200.0, 200.0, 250.0]
}

A1PdfSign::newSignature()
    ->certificate($pfx, $password)
    ->pdf($template)
    ->intoField('SignatureManager')
    ->seal()             // drawn into the field's own rectangle
    ->sign();
```

A field that is missing or already signed **raises rather than falling back to
appending**. That fallback is the failure this prevents: a signature that is
valid and in the wrong place, with the template's field still empty.

## Placing one

```php
A1PdfSign::addSignatureField($pdf, 'Manager');                                     // invisible
A1PdfSign::addSignatureField($pdf, 'Manager', new SealPlacement(60, 400, 120, 40)); // placed
```

Invisible is the default because it is the safe one: a rectangle is only
meaningful against a page whose size you know, and a field dropped at a guessed
rectangle lands over the text.

**Half a placement is refused.** A width with no height is not a smaller
rectangle, it is an unanswerable question, and the engine says so by name
rather than completing it by guessing.

## From the command line

```bash
php artisan pdf:fields template.pdf
php artisan pdf:add-field template.pdf Manager placed.pdf --x=60 --y=400 --width=120 --height=40
```
