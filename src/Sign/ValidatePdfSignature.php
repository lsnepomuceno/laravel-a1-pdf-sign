<?php

namespace LSNepomuceno\LaravelA1PdfSign\Sign;

use Illuminate\Support\{Arr, Facades\File, Str};
use LSNepomuceno\LaravelA1PdfSign\Entities\ValidatedSignedPDF;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\{FileNotFoundException,
    HasNoSignatureOrInvalidPkcs7Exception,
    InvalidPdfFileException,
    ProcessRunTimeException
};
use Throwable;

class ValidatePdfSignature
{
    private string $pdfPath, $plainTextContent, $pkcs7Path = '';

    /**
     * @throws Throwable
     */
    public static function from(string $pdfPath): ValidatedSignedPDF
    {
        return (new static)->setPdfPath($pdfPath)
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
        $regexp = '#ByteRange\[\s*(\d+) (\d+) (\d+)#'; // subexpressions are used to extract b and c
        $result = [];
        preg_match_all($regexp, $content, $result);

        // $result[2][0] and $result[3][0] are b and c
        if (!isset($result[2][0]) && !isset($result[3][0])) {
            throw new HasNoSignatureOrInvalidPkcs7Exception($this->pdfPath);
        }

        $start = $result[2][0];
        $end = $result[3][0];

        if ($stream = fopen($this->pdfPath, 'rb')) {
            $signature = stream_get_contents($stream, $end - $start - 2, $start + 1); // because we need to exclude < and > from start and end
            fclose($stream);
            $this->pkcs7Path = a1TempDir(tempFile: true, fileExt: '.pkcs7');
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

        $output = a1TempDir(tempFile: true, fileExt: '.txt');
        $openSslCommand = "openssl pkcs7 -in {$this->pkcs7Path} -inform DER -print_certs > {$output}";

        runCliCommandProcesses($openSslCommand);

        if (!File::exists($output)) {
            throw new FileNotFoundException($output);
        }

        $this->plainTextContent = File::get($output);

        File::delete([$output, $this->pkcs7Path]);

        return $this;
    }

    private function convertPlainTextToObject(): ValidatedSignedPDF
    {
        $finalContent = [];
        $delimiter = '|CROP|';
        $content = $this->plainTextContent;
        $content = preg_replace('/(-----BEGIN .+?-----(?s).+?-----END .+?-----)/mi', $delimiter, $content);
        $content = preg_replace('/(\s\s+|\\n|\\r)/', ' ', $content);
        $content = array_filter(explode($delimiter, $content), 'trim');
        $content = (array)array_map(fn($data) => $this->processDataToInfo($data), $content)[0];

        foreach ($content as $value) {
            $val = $value[key($value)];
            $key = &$finalContent[key($value)];

            !in_array($val, ($key ?? [])) && ($key[] = $val);
        }

        $finalContent['validated'] = !!count(array_intersect_key(array_flip(['OU', 'CN']), $finalContent));
        return new ValidatedSignedPDF($finalContent['validated'], Arr::except($finalContent, 'validated'));
    }

    private function processDataToInfo(string $data): array
    {
        $data = explode(', ', trim($data));

        $finalData = [];

        foreach ($data as $info) {
            $infoTemp = explode(' = ', trim($info));
            if (isset($infoTemp[0]) && $infoTemp[1]) {
                $finalData[] = [$infoTemp[0] => $infoTemp[1]];
            }
        }
        return $finalData;
    }
}
