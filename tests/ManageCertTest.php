<?php

namespace LSNepomuceno\LaravelA1PdfSign\Tests;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Fluent;
use LSNepomuceno\LaravelA1PdfSign\ManageCert;
use Orchestra\Testbench\TestCase;
use Throwable;
use LSNepomuceno\LaravelA1PdfSign\Exception\{
  FileNotFoundException,
  InvalidPFXException
};

class ManageCertTest extends TestCase
{
  public function testValidateCertificateStructureFromPfxFile()
  {
    try {
      $cert = new ManageCert;
      $cert->makeDebugCertificate();
    } catch (Throwable $th) {
      throw $th;
    }

    $this->assertInstanceOf(Fluent::class, $cert->getCert());

    foreach (['original', 'openssl', 'data', 'password'] as $key) {
      $this->assertArrayHasKey($key, $cert->getCert()->toArray());
    }

    $this->assertStringContainsStringIgnoringCase('BEGIN CERTIFICATE', $cert->getCert()->original);
    $this->assertIsResource($cert->getCert()->openssl);
    $this->assertIsArray($cert->getCert()->data);
    $this->assertArrayHasKey('validTo_time_t', $cert->getCert()->data); //important field
    $this->assertNotNull($cert->getCert()->password);
  }

  public function testValidateNotFoundPfxFileException()
  {
    $this->expectException(FileNotFoundException::class);

    try {
      $cert = new ManageCert;
      $cert->fromPfx('imaginary/path/to/file.pfx', '12345');
    } catch (FileNotFoundException $th) {
      throw $th;
    }
  }

  public function testValidatePfxFileExtensionException()
  {
    $this->expectException(InvalidPFXException::class);

    try {
      $cert = new ManageCert;
      $cert->fromPfx('imaginary/path/to/file.pfz', '12345');
    } catch (InvalidPFXException $th) {
      throw $th;
    }
  }

  public function testValidateEncryperInstanceAndResources()
  {
    try {
      $cert = new ManageCert;
      $cert->makeDebugCertificate();
    } catch (Throwable $th) {
      throw $th;
    }

    $this->assertInstanceOf(Encrypter::class, $cert->getEncrypter());
    $this->assertTrue(
      $cert->getEncrypter()->supported($cert->getHashKey(), $cert::CIPHER)
    );
  }
}
