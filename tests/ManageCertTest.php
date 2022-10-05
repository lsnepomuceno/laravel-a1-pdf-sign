<?php

namespace LSNepomuceno\LaravelA1PdfSign\Tests;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\File;
use LSNepomuceno\LaravelA1PdfSign\Entities\CertificateProcessed;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\{CertificateOutputNotFoundException,
    FileNotFoundException,
    InvalidCertificateContentException,
    InvalidPFXException,
    Invalidx509PrivateKeyException,
    ProcessRunTimeException
};
use LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert;
use OpenSSLCertificate;

class ManageCertTest extends TestCase
{
    /**
     * @throws FileNotFoundException
     * @throws InvalidCertificateContentException
     * @throws CertificateOutputNotFoundException
     * @throws InvalidPFXException
     * @throws ProcessRunTimeException
     * @throws Invalidx509PrivateKeyException
     */
    public function testValidateCertificateStructureFromPfxFile()
    {
        $cert = new ManageCert;
        $cert->makeDebugCertificate();

        $this->assertInstanceOf(CertificateProcessed::class, $cert->getCert());

        foreach (['original', 'openssl', 'data', 'password'] as $key) {
            $this->assertArrayHasKey($key, $cert->getCert()->toArray());
        }

        $this->assertStringContainsStringIgnoringCase('BEGIN CERTIFICATE', $cert->getCert()->original);

        is_object($cert->getCert()->openssl)
            ? $this->assertInstanceOf(OpenSSLCertificate::class, $cert->getCert()->openssl)
            : $this->assertIsResource($cert->getCert()->openssl);

        $this->assertIsArray($cert->getCert()->data);
        $this->assertArrayHasKey('validTo_time_t', $cert->getCert()->data); //important field
        $this->assertNotNull($cert->getCert()->password);
    }

    /**
     * @throws InvalidCertificateContentException
     * @throws InvalidPFXException
     * @throws CertificateOutputNotFoundException
     * @throws ProcessRunTimeException
     * @throws Invalidx509PrivateKeyException
     */
    public function testValidateNotFoundPfxFileException()
    {
        $this->expectException(FileNotFoundException::class);

        $cert = new ManageCert;
        $cert->fromPfx('imaginary/path/to/file.pfx', '12345');
    }

    /**
     * @throws FileNotFoundException
     * @throws InvalidCertificateContentException
     * @throws CertificateOutputNotFoundException
     * @throws ProcessRunTimeException
     * @throws Invalidx509PrivateKeyException
     */
    public function testValidatePfxFileExtensionException()
    {
        $this->expectException(InvalidPFXException::class);

        $cert = new ManageCert;
        $cert->fromPfx('imaginary/path/to/file.pfz', '12345');
    }

    /**
     * @throws FileNotFoundException
     * @throws InvalidCertificateContentException
     * @throws CertificateOutputNotFoundException
     * @throws InvalidPFXException
     * @throws ProcessRunTimeException
     * @throws Invalidx509PrivateKeyException
     */
    public function testValidateEncryperInstanceAndResources()
    {
        $cert = new ManageCert;
        $cert->makeDebugCertificate();

        $this->assertInstanceOf(Encrypter::class, $cert->getEncrypter());
        $this->assertTrue(
            $cert->getEncrypter()->supported($cert->getHashKey(), $cert::CIPHER)
        );
    }

    /**
     * @throws FileNotFoundException
     * @throws InvalidCertificateContentException
     * @throws InvalidPFXException
     * @throws CertificateOutputNotFoundException
     * @throws Invalidx509PrivateKeyException
     */
    public function testValidateProcessRunTimeException()
    {
        $this->expectException(ProcessRunTimeException::class);

        $cert = new ManageCert;
        $cert->makeDebugCertificate(false, true);
    }

    /**
     * @throws FileNotFoundException
     * @throws ProcessRunTimeException
     * @throws Invalidx509PrivateKeyException
     * @throws InvalidCertificateContentException
     * @throws InvalidPFXException
     * @throws CertificateOutputNotFoundException
     */
    public function testValidatesIfThePfxFileWillBeDeletedAfterBeingPreserved()
    {
        $cert = new ManageCert;
        list($pfxPath, $pass) = $cert->makeDebugCertificate(true);

        $cert->setPreservePfx()->fromPfx($pfxPath, $pass);

        $this->assertTrue(File::exists($pfxPath));
        $this->assertTrue(File::delete($pfxPath));
    }
}
