<?php

namespace LSNepomuceno\LaravelA1PdfSign;

use setasign\Fpdi\Tcpdf\Fpdi;
use Illuminate\Support\{Str, Facades\File};
use LSNepomuceno\LaravelA1PdfSign\Exception\{FileNotFoundException, InvalidPdfFileException, InvalidPdfSignModeTypeException};

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

  /**
   * __construct
   *
   * @param  string $pdfPath
   * @param  \LSNepomuceno\LaravelA1PdfSign\ManageCert $cert
   * @param  string $mode self::MODE_RESOURCE
   * @throws \Throwable
   * @throws \LSNepomuceno\LaravelA1PdfSign\Exception\{FileNotFoundException,InvalidPdfSignModeTypeException}
   * @return void
   */
  public function __construct(string $pdfPath, ManageCert $cert, string $mode = self::MODE_RESOURCE)
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

    $this->mode    = $mode;
    $this->pdfPath = $pdfPath;
    $this->pdf     =  new Fpdi(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
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
    $password    = $this->cert->getCert()->password;
    $info        = [ // Future implementation
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

    $fileName = Str::orderedUuid() . '.pdf';
    $output   = "{$this->cert->getTempDir()}{$fileName}";

    if (!File::exists($output)) {
      // Required to receive data from the server, such as timestamp and allocation hash.
      File::put($output, $this->pdf->output($fileName, 'S'));
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
