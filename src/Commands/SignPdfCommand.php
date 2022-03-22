<?php

namespace LSNepomuceno\LaravelA1PdfSign\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SignPdfCommand extends Command
{
    protected
        $signature = 'sign:pdf
                           {--pdfPath : The path to the PDF file}
                           {--pfxPath : The path to the certificate file}
                           {--password : The certificate password}
                           {--fileName? : The signed file name}
        ',
        $description = 'Sign a pdf file';

    public function handle(): int
    {
        $this->line('Your PDF file is being signed!', 'info');

        try {
            $pdfPath = $this->argument(key: 'pdfPath');
            $pfxPath = $this->argument(key: 'pfxPath');
            $password = $this->argument(key: 'password');
            $fileName = $this->defineFileName($this->argument(key: 'fileName'));

            $signedFileResource = signPdfFromFile($pfxPath, $password, $pdfPath);

            File::put($fileName, $signedFileResource);

            $this->line("Your file has been signed and is available at: \"{$fileName}\"", 'info');

            return self::SUCCESS;
        } catch (\Throwable $th) {
            $this->line("Could not sign your file, error occurred: {$th->getMessage()}", 'error');
            return self::FAILURE;
        }
    }

    private function defineFileName(?string $fileName): string
    {
        if ($fileName && !Str::endsWith(strtolower($fileName), '.pdf')) {
            return "{$fileName}.pdf";
        }

        if (!$fileName) {
            $fileName = a1TempDir(tempFile: true, fileExt: '.pdf');
        }

        return $fileName;
    }
}
