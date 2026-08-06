<?php

namespace LSNepomuceno\LaravelA1PdfSign\Sign;

use Illuminate\Support\{Arr, Facades\File, Str};
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureReport;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\{FileNotFoundException,
    HasNoSignatureOrInvalidPkcs7Exception,
    InvalidPdfFileException,
    ProcessRunTimeException
};
use LSNepomuceno\LaravelA1PdfSign\Support\ProcessRunner;
use LSNepomuceno\LaravelA1PdfSign\Support\TemporaryFile;
use Throwable;

class ValidatePdfSignature
{
    private string $pdfPath;
    private string $plainTextContent;
    private string $pkcs7Path = '';

    /**
     * @throws Throwable
     */
    public static function from(string $pdfPath): SignatureReport
    {
        return (new static())->setPdfPath($pdfPath)
                           ->extractSignatureData()
                           ->convertSignatureDataToPlainText()
                           ->convertPlainTextToObject();
    }

    /**
     * @throws FileNotFoundException
     * @throws InvalidPdfFileException
     */
    private function setPdfPath(string $pdfPath): self
    {
        if (!Str::of($pdfPath)->lower()->endsWith('.pdf')) {
            throw new InvalidPdfFileException($pdfPath);
        }

        if (!File::exists($pdfPath)) {
            throw new FileNotFoundException($pdfPath);
        }

        $this->pdfPath = $pdfPath;

        return $this;
    }

    /**
     * @throws HasNoSignatureOrInvalidPkcs7Exception
     */
    private function extractSignatureData(): self
    {
        $content = File::get($this->pdfPath);
        $regexp  = '#ByteRange\[\s*(\d+) (\d+) (\d+)#'; // subexpressions are used to extract b and c
        $result  = [];
        preg_match_all($regexp, $content, $result);

        // $result[2][0] and $result[3][0] are b and c
        if (!isset($result[2][0]) && !isset($result[3][0])) {
            throw new HasNoSignatureOrInvalidPkcs7Exception($this->pdfPath);
        }

        $start = $result[2][0];
        $end   = $result[3][0];

        if ($stream = fopen($this->pdfPath, 'rb')) {
            $signature = stream_get_contents($stream, $end - $start - 2, $start + 1); // because we need to exclude < and > from start and end
            fclose($stream);
            $this->pkcs7Path = app(A1PdfSign::class)->tempPath(tempFile: true, fileExt: '.pkcs7');
            File::put($this->pkcs7Path, hex2bin($signature));
        }

        return $this;
    }

    /**
     * @throws FileNotFoundException
     * @throws HasNoSignatureOrInvalidPkcs7Exception
     * @throws ProcessRunTimeException
     */
    private function convertSignatureDataToPlainText(): self
    {
        if (!$this->pkcs7Path) {
            throw new HasNoSignatureOrInvalidPkcs7Exception($this->pdfPath);
        }

        $tempDir = app(A1PdfSign::class)->tempPath();

        try {
            $this->plainTextContent = TemporaryFile::with($tempDir, '.txt', '', function (TemporaryFile $out): string {
                app(ProcessRunner::class)->run(sprintf(
                    'openssl pkcs7 -in %s -inform DER -print_certs > %s',
                    escapeshellarg($this->pkcs7Path),
                    escapeshellarg($out->path),
                ));

                if (! $out->exists()) {
                    throw new FileNotFoundException($out->path);
                }

                return $out->contents();
            });
        } finally {
            // The extracted PKCS#7 blob goes even when openssl fails.
            File::delete($this->pkcs7Path);
        }

        return $this;
    }

    private function convertPlainTextToObject(): SignatureReport
    {
        $finalContent = [];
        $delimiter    = '|CROP|';
        $content      = $this->plainTextContent;
        $content      = preg_replace('/(-----BEGIN .+?-----(?s).+?-----END .+?-----)/mi', $delimiter, $content);
        $content      = preg_replace('/(\s\s+|\\n|\\r)/', ' ', $content);
        $content      = array_filter(explode($delimiter, $content), 'trim');
        $content      = array_map(fn(string $data) => $this->processDataToInfo($data), $content);

        // array_filter() preserves keys, so the first element is not necessarily
        // at index 0.
        $content      = (array) (reset($content) ?: []);

        foreach ($content as $value) {
            $val = $value[key($value)];
            $key = &$finalContent[key($value)];

            !in_array($val, ($key ?? [])) && ($key[] = $val);
        }

        $finalContent['validated'] = !!count(array_intersect_key(array_flip(['OU', 'CN']), $finalContent));
        return new SignatureReport($finalContent['validated'], Arr::except($finalContent, 'validated'));
    }

    private function processDataToInfo(string $data): array
    {
        /** it allows to split  by "," except when "," inside of quoutes */
        $data = preg_split('/\s*,\s*(?=(?:[^"]*"[^"]*")*[^"]*$)/', trim($data));

        $finalData = [];

        foreach ($data as $info) {
            $infoTemp = explode(' = ', trim($info));

            /**
             * OpenSSL up to 3.4 prints "key = value"; 3.5 dropped the spaces and
             * prints "key=value". Only fall back to the compact separator when the
             * spaced one is absent, so output from older releases keeps parsing
             * exactly as before.
             */
            if (!isset($infoTemp[1])) {
                $infoTemp = preg_split('/\s*=\s*/', trim($info), 2) ?: [];
            }

            if (isset($infoTemp[0], $infoTemp[1]) && $infoTemp[1] !== '') {
                $finalData[] = [$infoTemp[0] => $infoTemp[1]];
            }
        }
        return $finalData;
    }
}
