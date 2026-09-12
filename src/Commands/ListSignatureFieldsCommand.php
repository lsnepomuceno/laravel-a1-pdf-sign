<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Commands;

use Illuminate\Console\Command;
use LSNepomuceno\LaravelA1PdfSign\Commands\Concerns\ReadsTypedInput;
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use Throwable;

/**
 * What signature fields a document declares, signed or not.
 *
 * The question a template raises: `->intoField('Manager')` refuses when the
 * name is not there, and refusing is the right behaviour, so a way to ask what
 * the names actually are belongs next to it
 * (docs/decisions/0013-signing-into-an-existing-field.md).
 */
class ListSignatureFieldsCommand extends Command
{
    use ReadsTypedInput;

    protected $signature = 'pdf:fields
                           {pdfPath : The path to the PDF file}';

    protected $description = 'List the signature fields in a PDF document';

    public function handle(A1PdfSign $signing): int
    {
        try {
            $fields = $signing->signatureFields($this->stringArgument('pdfPath'));
        } catch (Throwable $exception) {
            $this->line("Could not read the document: {$exception->getMessage()}", 'error');

            return self::FAILURE;
        }

        if ($fields === []) {
            $this->line('This document declares no signature fields.', 'comment');

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Signed', 'Page', 'Visible'],
            array_map(static fn($field): array => [
                $field->name,
                $field->isSigned ? 'yes' : 'no',
                $field->pageNumber,
                $field->isVisible() ? 'yes' : 'no',
            ], $fields),
        );

        return self::SUCCESS;
    }
}
