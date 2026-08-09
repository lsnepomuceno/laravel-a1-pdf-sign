<?php

namespace LSNepomuceno\LaravelA1PdfSign\Commands;

use Illuminate\Console\Command;
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;

class ValidatePdfSignatureCommand extends Command
{
    protected $signature = 'pdf:validate-signature
                                {pdfPath : The path to the PDF file}
        ';
    protected $description = 'Validates whether the signature of the PDF file is valid';

    public function handle(): int
    {
        $this->line('Your PDF document is being validated.', 'info');
        try {
            $pdfPath = $this->stringArgument('pdfPath');

            $validated = app(A1PdfSign::class)->validate($pdfPath);
            $validationText = $validated->isValid() ? 'VALID' : 'INVALID';

            $this->line("Your PDF document is {$validationText}", 'info');

            foreach ($validated->signatures as $index => $signature) {
                $signer = $signature->signer()?->commonName;
                $signer = $signer ?? 'unknown signer';
                $status = $signature->verified ? 'verified' : 'NOT verified';
                $scope = $signature->coversWholeDocument ? 'covers the whole file' : 'covers its own revision';

                $this->line(sprintf('  %d. %s: %s, %s', $index + 1, $signer, $status, $scope), 'info');
            }

            return $validated->isValid() ? self::SUCCESS : self::INVALID;
        } catch (\Throwable $th) {
            $this->line("Unable to validate your file signature, an error occurred: {$th->getMessage()}", 'error');
            return self::FAILURE;
        }
    }

    /**
     * Console arguments are mixed; every one this command takes is a string.
     */
    private function stringArgument(string $key): string
    {
        $value = $this->argument($key);

        return is_string($value) ? $value : '';
    }
}
