<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use LSNepomuceno\LaravelA1PdfSign\Certificates\PemCertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPemContentException;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;

class SignPdfCommand extends Command
{
    protected $signature = 'pdf:sign
                           {pdfPath : The path to the PDF file}
                           {certificatePath : The path to the certificate, PKCS#12 or PEM}
                           {password : The certificate password, empty for an unencrypted PEM key}
                           {fileName? : The signed file name}
                           {--key= : The PEM private key, when it lives in its own file}
        ';
    protected $description = 'Sign a pdf file';

    public function handle(): int
    {
        $this->line('Your PDF file is being signed!', 'info');

        try {
            $pdfPath = $this->stringArgument('pdfPath');
            $certificatePath = $this->stringArgument('certificatePath');
            $password = $this->stringArgument('password');
            $fileName = $this->defineFileName($this->stringArgument('fileName'));
            $keyPath = $this->stringOption('key');

            $this->sign($certificatePath, $password, $pdfPath, $keyPath)->save($fileName);

            $this->line("Your file has been signed and is available at: \"{$fileName}\"", 'info');

            return self::SUCCESS;
        } catch (\Throwable $th) {
            $this->line("Could not sign your file, error occurred: {$th->getMessage()}", 'error');
            return self::FAILURE;
        }
    }

    /**
     * Routes on the certificate's encoding rather than on its extension: PEM
     * ships under half a dozen suffixes, and the content says so unambiguously
     * (docs/decisions/0007-pem-second-entry-one-pipeline.md).
     *
     * @throws \Throwable
     */
    private function sign(string $certificatePath, string $password, string $pdfPath, ?string $keyPath): SignedPdf
    {
        $signer = app(A1PdfSign::class);

        if (PemCertificateReader::looksLikePem(Files::read($certificatePath))) {
            return $signer->signFromPem($certificatePath, $password, $pdfPath, $keyPath);
        }

        if ($keyPath !== null) {
            throw new InvalidPemContentException('--key applies to PEM certificates; a PKCS#12 bundle already carries its key.');
        }

        return $signer->signFromFile($certificatePath, $password, $pdfPath);
    }

    /**
     * Console options are mixed; --key is either a path or absent.
     */
    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function defineFileName(string $fileName): string
    {
        if ($fileName !== '' && ! Str::endsWith(strtolower($fileName), '.pdf')) {
            return "{$fileName}.pdf";
        }

        if ($fileName === '') {
            $fileName = app(A1PdfSign::class)->tempPath(tempFile: true, fileExt: '.pdf');
        }

        return $fileName;
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
