<?php

namespace LSNepomuceno\LaravelA1PdfSign\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\CertificateOutputNotFoundException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPFXException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\Invalidx509PrivateKeyException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessRunTimeException;
use LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert;
use Throwable;

class HelpersTest extends TestCase
{
    /**
     * @throws FileNotFoundException
     * @throws ProcessRunTimeException
     * @throws Invalidx509PrivateKeyException
     * @throws Throwable
     * @throws InvalidCertificateContentException
     * @throws InvalidPFXException
     * @throws CertificateOutputNotFoundException
     */
    public function testWhenAFileIsSignedByTheSignPdfFromFileHelper()
    {
        $cert = new ManageCert;
        list($pfxPath, $pass) = $cert->makeDebugCertificate(true);

        $signed = signPdfFromFile($pfxPath, $pass, __DIR__ . '/Resources/test.pdf');
        $pdfPath = a1TempDir(true, '.pdf');

        File::put($pdfPath, $signed);
        $fileExists = File::exists($pdfPath);

        $this->assertTrue($fileExists);
        File::delete([$pfxPath, $pdfPath]);
    }

    /**
     * @throws FileNotFoundException
     * @throws ProcessRunTimeException
     * @throws Invalidx509PrivateKeyException
     * @throws Throwable
     * @throws InvalidCertificateContentException
     * @throws InvalidPFXException
     * @throws CertificateOutputNotFoundException
     */
    public function testWhenAFileIsSignedByTheSignPdfFromFileHelperUsingPathEnv()
    {
        $cert = new ManageCert;
        list($pfxPath, $pass) = $cert->makeDebugCertificate(true);

        $signed = signPdfFromFile(
            pfxPath: $pfxPath,
            password: $pass,
            pdfPath: __DIR__ . '/Resources/test.pdf',
            usePathEnv: true
        );
        $pdfPath = a1TempDir(true, '.pdf');

        File::put($pdfPath, $signed);
        $fileExists = File::exists($pdfPath);

        $this->assertTrue($fileExists);
        File::delete([$pfxPath, $pdfPath]);
    }

    /**
     * @throws FileNotFoundException
     * @throws ProcessRunTimeException
     * @throws Invalidx509PrivateKeyException
     * @throws Throwable
     * @throws InvalidCertificateContentException
     * @throws CertificateOutputNotFoundException
     * @throws InvalidPFXException
     */
    public function testWhenAFileIsSignedByTheSignPdfFromUploadHelper()
    {
        $cert = new ManageCert;
        list($pfxPath, $pass) = $cert->makeDebugCertificate(true);

        $uploadedFile = new UploadedFile($pfxPath, 'testCertificate.pfx', null, null, true);
        $signed = signPdfFromUpload($uploadedFile, $pass, __DIR__ . '/Resources/test.pdf');
        $pdfPath = a1TempDir(true, '.pdf');

        File::put($pdfPath, $signed);
        $fileExists = File::exists($pdfPath);

        $this->assertTrue($fileExists);
        File::delete([$pfxPath, $pdfPath]);
    }

    /**
     * @throws FileNotFoundException
     * @throws ProcessRunTimeException
     * @throws Invalidx509PrivateKeyException
     * @throws Throwable
     * @throws InvalidCertificateContentException
     * @throws CertificateOutputNotFoundException
     * @throws InvalidPFXException
     */
    public function testWhenAFileIsSignedByTheSignPdfFromUploadHelperUsingPathEnv()
    {
        $cert = new ManageCert;
        list($pfxPath, $pass) = $cert->makeDebugCertificate(true);

        $uploadedFile = new UploadedFile($pfxPath, 'testCertificate.pfx', null, null, true);
        $signed = signPdfFromUpload(
            uploadedPfx: $uploadedFile,
            password: $pass,
            pdfPath: __DIR__ . '/Resources/test.pdf',
            usePathEnv: true
        );
        $pdfPath = a1TempDir(true, '.pdf');

        File::put($pdfPath, $signed);
        $fileExists = File::exists($pdfPath);

        $this->assertTrue($fileExists);
    }

    /**
     * @throws FileNotFoundException
     * @throws ProcessRunTimeException
     * @throws Invalidx509PrivateKeyException
     * @throws Throwable
     * @throws InvalidCertificateContentException
     * @throws CertificateOutputNotFoundException
     * @throws InvalidPFXException
     */
    public function testWhenCertificateDataIsEncrypted()
    {
        $cert = new ManageCert;
        list($pfxPath, $pass) = $cert->makeDebugCertificate(true);

        $encryptedData = encryptCertData($pfxPath, $pass);

        foreach (['certificate', 'password', 'hash'] as $key) {
            $this->assertArrayHasKey($key, $encryptedData->toArray());
        }
    }

    public function testWhenTheA1TempDirHelperCreatesTheFilesCorrectly()
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

    /**
     * @throws FileNotFoundException
     * @throws ProcessRunTimeException
     * @throws Invalidx509PrivateKeyException
     * @throws InvalidCertificateContentException
     * @throws Throwable
     * @throws CertificateOutputNotFoundException
     * @throws InvalidPFXException
     */
    public function testWhenASignedPdfFileIsCorrectlyValidatedByTheValidatePdfSignatureHelper()
    {
        $cert = new ManageCert;
        list($pfxPath, $pass) = $cert->makeDebugCertificate(true);

        $signed = signPdfFromFile($pfxPath, $pass, __DIR__ . '/Resources/test.pdf');
        $pdfPath = a1TempDir(true, '.pdf');

        File::put($pdfPath, $signed);
        $fileExists = File::exists($pdfPath);

        $this->assertTrue($fileExists);

        $validation = validatePdfSignature($pdfPath);
        $this->assertTrue($validation->isValidated);
    }
}
