<?php

namespace LSNepomuceno\LaravelA1PdfSign\Sign;

use Illuminate\Support\{Facades\File, Str};
use LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfSignModeTypeException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidX509PrivateKeyException;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\Filter\FilterException;
use setasign\Fpdi\PdfParser\PdfParserException;
use setasign\Fpdi\PdfParser\Type\PdfTypeException;
use setasign\Fpdi\PdfReader\PdfReaderException;
use setasign\Fpdi\Tcpdf\Fpdi;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class SignaturePdf
{
    const
        MODE_DOWNLOAD = 'MODE_DOWNLOAD',
        MODE_RESOURCE = 'MODE_RESOURCE';

    private Fpdi $pdf;

    private ManageCert $cert;

    private string $pdfPath, $mode, $fileName;

    private ?array $image = null;

    private array $info = [];

    private bool $hasSignedSuffix, $hasSealImgOnEveryPages;

    /**
     * @throws InvalidPdfSignModeTypeException
     * @throws FileNotFoundException
     * @throws InvalidCertificateContentException
     * @throws Throwable
     * @throws InvalidX509PrivateKeyException
     */
    public function __construct(
        string     $pdfPath,
        ManageCert $cert,
        string     $mode = self::MODE_RESOURCE,
        string     $fileName = '',
        bool       $hasSignedSuffix = true)
    {
        /**
         * @throws FileNotFoundException
         */
        if (!File::exists($pdfPath)) throw new FileNotFoundException($pdfPath);

        /**
         * @throws InvalidPdfSignModeTypeException
         */
        if (!in_array($mode, [self::MODE_RESOURCE, self::MODE_DOWNLOAD])) throw new InvalidPdfSignModeTypeException($mode);

        $this->cert = $cert;

        // Throws exception on invalidate certificate
        try {
            $this->cert->validate();
        } catch (Throwable $th) {
            throw new $th;
        }

        $this->setFileName($fileName)
            ->setHasSignedSuffix($hasSignedSuffix)
            ->setSealImgOnEveryPages(false);

        $this->mode = $mode;
        $this->pdfPath = $pdfPath;
        $this->setPdf();
    }

    public function setInfo(
        ?string $name = null,
        ?string $location = null,
        ?string $reason = null,
        ?string $contactInfo = null
    ): SignaturePdf
    {
        $info = [];
        $name && ($info['Name'] = $name);
        $location && ($info['Location'] = $location);
        $reason && ($info['Reason'] = $reason);
        $contactInfo && ($info['ContactInfo'] = $contactInfo);
        $this->info = $info;
        return $this;
    }

    public function getPdfInstance(): Fpdi
    {
        return $this->pdf;
    }

    public function setPdf(
        string $orientation = 'P',
        string $unit = 'mm',
        string $pageFormat = 'A4',
        bool   $unicode = true,
        string $encoding = 'UTF-8'
    ): SignaturePdf
    {
        $this->pdf = new Fpdi($orientation, $unit, $pageFormat, $unicode, $encoding);
        return $this;
    }

    public function setImage(
        string $imagePath,
        float  $pageX = 155,
        float  $pageY = 250,
        float  $imageW = 50,
        float  $imageH = 0,
        int    $page = -1
    ): SignaturePdf
    {
        $this->image = compact('imagePath', 'pageX', 'pageY', 'imageW', 'imageH', 'page');
        return $this;
    }

    public function setSealImgOnEveryPages(bool $hasSealImgOnEveryPages = true): SignaturePdf
    {
        $this->hasSealImgOnEveryPages = $hasSealImgOnEveryPages;
        return $this;
    }

    public function setFileName(string $fileName): SignaturePdf
    {
        $ext = explode('.', $fileName);
        $ext = end($ext);
        $this->fileName = str_replace(".{$ext}", '', $fileName);
        return $this;
    }

    public function setHasSignedSuffix(bool $hasSignedSuffix): SignaturePdf
    {
        $this->hasSignedSuffix = $hasSignedSuffix;
        return $this;
    }

    private function implementSignatureImage(?int $currentPage = null): void
    {
        if ($this->image) {
            extract($this->image);
            $this->pdf->Image(
                $this->image['imagePath'],
                $this->image['pageX'],
                $this->image['pageY'],
                $this->image['imageW'],
                $this->image['imageH'],
                'PNG'
            );
            $this->pdf->setSignatureAppearance(
                $this->image['pageX'],
                $this->image['pageY'],
                $this->image['imageW'],
                $this->image['imageH'],
                $currentPage ?? $this->image['page']
            );
        }
    }

    /**
     * @throws CrossReferenceException
     * @throws FilterException
     * @throws PdfParserException
     * @throws PdfTypeException
     * @throws PdfReaderException
     */
    public function signature(): string|BinaryFileResponse
    {
        $pageCount = $this->pdf->setSourceFile($this->pdfPath);

        for ($i = 1; $i <= $pageCount; $i++) {
            $pageIndex = $this->pdf->importPage($i);
            $this->pdf->SetPrintHeader(false);
            $this->pdf->SetPrintFooter(false);

            $templateSize = $this->pdf->getTemplateSize($pageIndex);
            ['width' => $width, 'height' => $height] = $templateSize;

            $this->pdf->AddPage($width > $height ? 'L' : 'P', [$width, $height]);
            $this->pdf->useTemplate($pageIndex);

            $insertImageOnLastPage = !empty($this->image['page']) && $this->image['page'] === -1 && $i === $pageCount;
            if ($this->hasSealImgOnEveryPages ||
                $i === ($this->image['page'] ?? 0) ||
                $insertImageOnLastPage
            ) {
                $this->implementSignatureImage($i);
            }
        }

        $certificate = $this->cert->getCert()->original;
        $password = $this->cert->getCert()->password;

        $this->pdf->setSignature(
            $certificate,
            $certificate,
            $password,
            '',
            3,
            $this->info,
            'A' // Authorize certificate
        );

        if (empty($this->fileName)) $this->fileName = Str::orderedUuid();
        if ($this->hasSignedSuffix) $this->fileName .= '_signed';

        $this->fileName .= '.pdf';

        $output = "{$this->cert->getTempDir()}{$this->fileName}";

        // Required to receive data from the server, such as timestamp and allocation hash.
        if (!File::exists($output)) File::put($output, $this->pdf->output($this->fileName, 'S'));

        switch ($this->mode) {
            case self::MODE_RESOURCE:
                $content = File::get($output);
                File::delete([$output]);
                return $content;
                break;

            case self::MODE_DOWNLOAD:
            default:
                return response()->download($output)->deleteFileAfterSend();
                break;
        }
    }
}
