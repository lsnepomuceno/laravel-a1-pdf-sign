<?php

namespace LSNepomuceno\LaravelA1PdfSign;

use Illuminate\Support\{Facades\File, Str};
use LSNepomuceno\LaravelA1PdfSign\Exception\{FileNotFoundException, InvalidPdfSignModeTypeException};
use setasign\Fpdi\Tcpdf\Fpdi;

class SignaturePdf
{
    /**
     * @var string
     */
    const
        MODE_DOWNLOAD = 'MODE_DOWNLOAD',
        MODE_RESOURCE = 'MODE_RESOURCE';

    /**
     * @var \setasign\Fpdi\Tcpdf\Fpdi
     */
    private Fpdi $pdf;

    /**
     * @var \LSNepomuceno\LaravelA1PdfSign\ManageCert
     */
    private ManageCert $cert;

    /**
     * @var string
     */
    private string $pdfPath, $mode;
    private $fileName;

    /**
     * @var array|null
     */
    private ?array $image = null;

    /**
     * __construct
     *
     * @param string $pdfPath
     * @param $fileName
     * @param \LSNepomuceno\LaravelA1PdfSign\ManageCert $cert
     * @param string $mode self::MODE_RESOURCE
     * @return void
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exception\{FileNotFoundException,InvalidPdfSignModeTypeException}
     * @throws \Throwable
     */
    public function __construct(string $pdfPath, $fileName, ManageCert $cert, string $mode = self::MODE_RESOURCE)
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
        } catch (\Throwable $th) {
            throw $th;
        }

        $this->mode = $mode;
        $this->pdfPath = $pdfPath;
        $this->pdf = new Fpdi(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        if ($fileName !== null) {
            $this->fileName = $fileName;
        } else {
            $this->fileName = Str::orderedUuid() . '.pdf';
        }
    }

    /**
     * setImage - Defines an image as a signature identifier
     *
     * @param string $imagePath - Support only for PNG images
     * @param float $pageX
     * @param float $pageY
     * @param float $imageH
     * @param float $imageW
     *
     * @return \LSNepomuceno\LaravelA1PdfSign\SignaturePdf
     */
    public function setImage(
        string $imagePath,
        float $pageX = 155,
        float $pageY = 250,
        float $imageW = 50,
        float $imageH = 0
    ): SignaturePdf
    {
        $this->image = compact('imagePath', 'pageX', 'pageY', 'imageW', 'imageH');
        return $this;
    }

    /**
     * signature - Sign a PDF file
     *
     * @return mixed
     */
    public function signature()
    {
        $pagecount = $this->pdf->setSourceFile($this->pdfPath);

        for ($i = 1; $i <= $pagecount; $i++) {
            $tplidx = $this->pdf->importPage($i);
            $this->pdf->SetPrintHeader(false);
            $this->pdf->SetPrintFooter(false);
            $this->pdf->AddPage();
            $this->pdf->useTemplate($tplidx);
        }

        $certificate = $this->cert->getCert()->original;
        $password = $this->cert->getCert()->password;
        $info = [ // Future implementation
            // 'Name'        => '',
            // 'Location'    => '',
            // 'Reason'      => '',
            // 'ContactInfo' => '',
        ];

        $this->pdf->setSignature(
            $certificate,
            $certificate,
            $password,
            '',
            3,
            $info,
            'A' // Authorize certificate
        );

        if ($this->image) {
            extract($this->image);
            $this->pdf->Image($imagePath, $pageX, $pageY, $imageW, $imageH, 'PNG');
            $this->pdf->setSignatureAppearance($pageX, $pageY, $imageW, $imageH);
        }

        $output = "{$this->cert->getTempDir()}{$this->fileName}";

        if (!File::exists($output)) {
            // Required to receive data from the server, such as timestamp and allocation hash.
            File::put($output, $this->pdf->output($this->fileName, 'S'));
        }

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
