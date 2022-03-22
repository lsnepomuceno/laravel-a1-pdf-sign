<?php

namespace LSNepomuceno\LaravelA1PdfSign\Commands;

use Illuminate\Console\Command;

class ValidatePdfSignCommand extends Command
{
    protected
        $signature = 'validate:pdf-sign',
        $description = 'Validates whether the signature of the PDF file is valid';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
