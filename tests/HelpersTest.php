<?php

namespace LSNepomuceno\LaravelA1PdfSign\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LSNepomuceno\LaravelA1PdfSign\ManageCert;
use \Illuminate\Http\UploadedFile;
use Orchestra\Testbench\TestCase;

class HelpersTest extends TestCase
{
  public function testValidateSignPdfFromFileHelper()
  {
    $cert = new ManageCert;
    list($pfxPath, $pass) = $cert->makeDebugCertificate(true);

    $signed     = signPdfFromFile($pfxPath, $pass, __DIR__ . '/Resources/test.pdf');
    $pdfPath    = a1TempDir(true, '.pdf');

    File::put($pdfPath, $signed);
    $fileExists = File::exists($pdfPath);

    $this->assertTrue($fileExists);
    File::delete([$pfxPath, $pdfPath]);
  }

  public function testValidateSignPdfFromUploadHelper()
  {
    $cert = new ManageCert;
    list($pfxPath, $pass) = $cert->makeDebugCertificate(true);

    $uploadedFile = new UploadedFile($pfxPath, 'testCertificate.pfx', null, null, true);
    $signed       = signPdfFromUpload($uploadedFile, $pass, __DIR__ . '/Resources/test.pdf');
    $pdfPath      = a1TempDir(true, '.pdf');

    File::put($pdfPath, $signed);
    $fileExists = File::exists($pdfPath);

    $this->assertTrue($fileExists);
    File::delete([$pfxPath, $pdfPath]);
  }

  public function testValidateEncryptCertDataHelper()
  {
    $cert = new ManageCert;
    list($pfxPath, $pass) = $cert->makeDebugCertificate(true);

    $encryptedData = encryptCertData($pfxPath, $pass);

    foreach (['certificate', 'password', 'hash'] as $key) {
      $this->assertArrayHasKey($key, $encryptedData->toArray());
    }
  }

  public function testValidateA1TempDirHelper()
  {
    $this->assertTrue(
      File::isDirectory(a1TempDir())
    );

    $this->assertTrue(
      Str::endsWith(a1TempDir(true), '.pfx')
    );

    $this->assertTrue(
      Str::endsWith(
        a1TempDir(true, '.pdf'),
        '.pdf'
      )
    );
  }

  public function testValidatePdfSignatureHelper()
  {
    $cert = new ManageCert;
    list($pfxPath, $pass) = $cert->makeDebugCertificate(true);

    $signed     = signPdfFromFile($pfxPath, $pass, __DIR__ . '/Resources/test.pdf');
    $pdfPath    = a1TempDir(true, '.pdf');

    File::put($pdfPath, $signed);
    $fileExists = File::exists($pdfPath);

    $this->assertTrue($fileExists);

    $validation = validatePdfSignature($pdfPath);
    $this->assertTrue($validation->validated);

    File::delete([$pfxPath, $pdfPath]);
  }
}
