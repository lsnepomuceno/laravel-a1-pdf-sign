<?php

namespace LSNepomuceno\LaravelA1PdfSign\Commands;

use Illuminate\Console\Command;

class ValidatePdfSignatureCommand extends Command
{
    protected
        $signature = 'validate:pdf-sign
                                {--pdfPath : The path to the PDF file}
        ',
        $description = 'Validates whether the signature of the PDF file is valid';

    public function handle(): int
    {
        $this->line('Your PDF document is being validated.', 'info');
        try {
            $pdfPath = $this->argument(key: 'pdfPath');

            $validated = validatePdfSignature($pdfPath);
            $validationText = $validated->isValidated ? 'VALID' : 'INVALID';

            $this->line("Your PDF document is {$validationText}", 'info');

            return self::SUCCESS;
        } catch (\Throwable $th) {
            $this->line("Could not sign your file, error occurred: {$th->getMessage()}", 'error');
            return self::FAILURE;
        }
    }
}
