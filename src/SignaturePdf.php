<?php

namespace LSNepomuceno\LaravelA1PdfSign;

use setasign\Fpdi\Tcpdf\Fpdi;
use Illuminate\Support\{Str, Facades\File};
use LSNepomuceno\LaravelA1PdfSign\Exception\{FileNotFoundException, InvalidPdfSignModeTypeException};
use Throwable;

class SignaturePdf
{
    private bool $hasSignedSuffix;
    private ?array $image = null;
    private array $info = [];
    private Fpdi $pdf;

    const
        MODE_DOWNLOAD = 'MODE_DOWNLOAD',
        MODE_RESOURCE = 'MODE_RESOURCE';

    /**
     * @throws Throwable
     * @throws FileNotFoundException
     * @throws Exception\InvalidCertificateContentException
     * @throws InvalidPdfSignModeTypeException
     * @throws Exception\Invalidx509PrivateKeyException
     */
    public function __construct(
        private string     $pdfPath,
        private ManageCert $cert,
        private string     $mode = self::MODE_RESOURCE,
        private string     $fileName = '',
        bool               $hasSignedSuffix = true
    )
    {
        if (!File::exists($this->pdfPath)) throw new FileNotFoundException($this->pdfPath);

        if (!in_array($this->mode, [self::MODE_RESOURCE, self::MODE_DOWNLOAD])) {
            throw new InvalidPdfSignModeTypeException($this->mode);
        }

        // Throws exception on invalidate certificate
        $this->cert->validate();

        $this->setFileName($this->fileName)
            ->setHasSignedSuffix($hasSignedSuffix);

        $this->setPdf();
    }

    public function setInfo(
        ?string $name = null,
        ?string $location = null,
        ?string $reason = null,
        ?string $contactInfo = null
    ): self
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
    ): self
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
    ): self
    {
        $this->image = compact('imagePath', 'pageX', 'pageY', 'imageW', 'imageH', 'page');
        return $this;
    }

    public function setFileName(string $fileName): self
    {
        $ext = explode('.', $fileName);
        $ext = end($ext);
        $this->fileName = str_replace(search: ".{$ext}", replace: '', subject: $fileName);
        return $this;
    }

    public function setHasSignedSuffix(bool $hasSignedSuffix): self
    {
        $this->hasSignedSuffix = $hasSignedSuffix;
        return $this;
    }

    /**
     * @throws \setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException
     * @throws \setasign\Fpdi\PdfReader\PdfReaderException
     * @throws \setasign\Fpdi\PdfParser\PdfParserException
     * @throws \setasign\Fpdi\PdfParser\Type\PdfTypeException
     * @throws \setasign\Fpdi\PdfParser\Filter\FilterException
     */
    public function signature(): string
    {
        $pageCount = $this->pdf->setSourceFile($this->pdfPath);

        for ($i = 1; $i <= $pageCount; $i++) {
            $tplidx = $this->pdf->importPage($i);
            $this->pdf->SetPrintHeader(false);
            $this->pdf->SetPrintFooter(false);
            $this->pdf->AddPage();
            $this->pdf->useTemplate($tplidx);
        }

        $certificate = $this->cert->getCert()->original;
        $password = $this->cert->getCert()->password;

        $this->pdf->setSignature(
            signing_cert: $certificate,
            private_key: $certificate,
            private_key_password: $password,
            extracerts: '',
            cert_type: 3,
            info: $this->info,
            approval: 'A' // Authorize certificate
        );

        if ($this->image) {
            $this->pdf->Image(
                file: $this->image['imagePath'],
                x: $this->image['pageX'],
                y: $this->image['pageY'],
                w: $this->image['imageW'],
                h: $this->image['imageH'],
                type: 'PNG'
            );
            $this->pdf->setSignatureAppearance(
                x: $this->image['pageX'],
                y: $this->image['pageY'],
                w: $this->image['imageW'],
                h: $this->image['imageH'],
                page: $this->image['page']
            );
        }

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
