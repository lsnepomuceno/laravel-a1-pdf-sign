<?php

namespace LSNepomuceno\LaravelA1PdfSign;

use Illuminate\Support\{Str, Fluent, Facades\File};
use LSNepomuceno\LaravelA1PdfSign\Exception\{FileNotFoundException, HasNoSignatureOrInvalidPkcs7Exception, InvalidPdfFileException, ProcessRunTimeException};
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ValidatePdfSignature
{
  /**
   * @var string
   */
  private string $pdfPath, $plainTextContent, $pkcs7Path = '';

  /**
   * from - Defines PDF file to validate
   *
   * @param  string $pdfPath
   * @throws \Throwable
   * @return \Illuminate\Support\Fluent
   */
  public static function from(string $pdfPath): Fluent
  {
    return (new static)->setPdfPath($pdfPath)
      ->extractSignatureData()
      ->convertSignatureDataToPlainText()
      ->convertPlainTextToObject();
  }

  /**
   * setPdfPath - Set pdf path
   *
   * @param  string $pdfPath
   * @throws \LSNepomuceno\LaravelA1PdfSign\Exception\{FileNotFoundException,InvalidPdfFileException}
   * @return \LSNepomuceno\LaravelA1PdfSign\ValidatePdfSignature
   */
  private function setPdfPath(string $pdfPath): ValidatePdfSignature
  {
    /**
     * @throws InvalidPdfFileException
     * @throws FileNotFoundException
     */
    if (!Str::of($pdfPath)->lower()->endsWith('.pdf')) throw new InvalidPdfFileException($pdfPath);
    if (!File::exists($pdfPath)) throw new FileNotFoundException($pdfPath);

    $this->pdfPath = $pdfPath;

    return $this;
  }

  /**
   * extractSignatureData - Extract signature from pdf file and send to temporary .pkcs7 file
   *
   * @throws \LSNepomuceno\LaravelA1PdfSign\Exception\HasNoSignatureOrInvalidPkcs7Exception
   *
   * @return \LSNepomuceno\LaravelA1PdfSign\ValidatePdfSignature
   */
  private function extractSignatureData(): ValidatePdfSignature
  {
    $content = File::get($this->pdfPath);
    $regexp  = '#ByteRange\[\s*(\d+) (\d+) (\d+)#'; // subexpressions are used to extract b and c
    $result  = [];
    preg_match_all($regexp, $content, $result);

    /**
     * @throws HasNoSignatureOrInvalidPkcs7Exception
     */
    // $result[2][0] and $result[3][0] are b and c
    if (!isset($result[2][0]) && !isset($result[3][0])) throw new HasNoSignatureOrInvalidPkcs7Exception($this->pdfPath);

    $start = $result[2][0];
    $end   = $result[3][0];
    if ($stream  = fopen($this->pdfPath, 'rb')) {
      $signature = stream_get_contents($stream, $end - $start - 2, $start + 1); // because we need to exclude < and > from start and end
      fclose($stream);
      $this->pkcs7Path = a1TempDir(true, '.pkcs7');
      File::put($this->pkcs7Path, hex2bin($signature));
    }

    return $this;
  }

  /**
   * convertSignatureDataToPlainText - Convert the .pkcs7 file to a temporary text file
   *
   * @throws \LSNepomuceno\LaravelA1PdfSign\Exception\{FileNotFoundException,HasNoSignatureOrInvalidPkcs7Exception,ProcessRunTimeException}
   * @return \LSNepomuceno\LaravelA1PdfSign\ValidatePdfSignature
   */
  private function convertSignatureDataToPlainText(): ValidatePdfSignature
  {
    /**
     * @throws HasNoSignatureOrInvalidPkcs7Exception
     */
    if (!$this->pkcs7Path) throw new HasNoSignatureOrInvalidPkcs7Exception($this->pdfPath);

    $output  = a1TempDir(true, '.txt');
    $command = "openssl pkcs7 -in {$this->pkcs7Path} -inform DER -print_certs > {$output}";

    try {
      $process = Process::fromShellCommandline($command);
      $process->run();

      while ($process->isRunning());

      /**
       * @throws ProcessRunTimeException
       */
      if (!$process->isSuccessful()) throw new ProcessRunTimeException($process->getErrorOutput());

      $process->stop(1);
    } catch (ProcessFailedException $exception) {
      throw $exception;
    }

    /**
     * @throws FileNotFoundException
     */
    if (!File::exists($output)) throw new FileNotFoundException($output);

    $this->plainTextContent = File::get($output);

    File::delete([$output, $this->pkcs7Path]);

    return $this;
  }

  /**
   * convertPlainTextToObject - Convert plain text to a Fluent object
   * @link https://laravel.com/api/8.x/Illuminate/Support/Fluent.html
   *
   * @return \Illuminate\Support\Fluent
   */
  private function convertPlainTextToObject(): Fluent
  {
    $finalContent = [];
    $delimiter = '|CROP|';
    $content   = $this->plainTextContent;
    $content   = preg_replace('/(-----BEGIN .+?-----(?s).+?-----END .+?-----)/mi', $delimiter, $content);
    $content   = preg_replace('/(\s\s+|\\n|\\r)/', ' ', $content);
    $content   = array_filter(explode($delimiter, $content), 'trim');
    $content   = (array) array_map(fn ($data) => $this->processDataToInfo($data), $content)[0];

    foreach ($content as $value) {
      $val = $value[key($value)];
      $key = &$finalContent[key($value)];

      !in_array($val, ($key ?? [])) && ($key[] = $val);
    }

    $finalContent['validated'] = !!count(array_intersect_key(array_flip(['OU', 'CN']), $finalContent));

    return new Fluent($finalContent);
  }

  /**
   * processDataToInfo - Process data for better formatting
   *
   * @param  string $data
   * @return array
   */
  private function processDataToInfo(string $data): array
  {
    $data = explode(', ', trim($data));

    $finalData = [];

    foreach ($data as $info) {
      $infoTemp = explode(' = ', trim($info));
      $finalData[] = [$infoTemp[0] => $infoTemp[1]];
    }
    return $finalData;
  }
}
