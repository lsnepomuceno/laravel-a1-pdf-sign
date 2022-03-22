<?php

namespace LSNepomuceno\LaravelA1PdfSign;

use LSNepomuceno\LaravelA1PdfSign\Exception\{InvalidImageDriverException};
use Intervention\Image\{ImageManager as IMG, Exception\NotReadableException};
use Illuminate\Support\Fluent;
use Closure;

class SealImage
{
    /**
     * @var string
     */
    private string $imagePathOrContent, $imageDriver;

    /**
     * @var array
     */
    private array $textFieldsDefinitions = [];

    /**
     * @var bool
     */
    private bool $previousTextBreakLine = false;

    /**
     * @var string
     */
    const
        IMAGE_DRIVER_GD = 'gd',
        IMAGE_DRIVER_IMAGICK = 'imagick',
        FONT_SIZE_SMALL = 'FONT_SIZE_SMALL',
        FONT_SIZE_MEDIUM = 'FONT_SIZE_MEDIUM',
        FONT_SIZE_LARGE = 'FONT_SIZE_LARGE',
        RETURN_IMAGE_CONTENT = 'RETURN_IMAGE_CONTENT',
        RETURN_BASE64 = 'RETURN_BASE64';

    /**
     * __construct
     *
     * @param mixed $imageDriver
     * @return void
     * @throws InvalidImageDriverException
     */
    public function __construct(string $imageDriver = self::IMAGE_DRIVER_GD)
    {
        $this->setImageDriver($imageDriver);
    }

    /**
     * fromCert - Generate a new seal based on certificate data
     *
     * @param ManageCert $cert
     * @param string $fontSize
     * @param bool $showDueDate
     * @param string $dueDateFormat
     *
     * @return string
     */
    public static function fromCert(
        ManageCert $cert,
        string     $fontSize = self::FONT_SIZE_LARGE,
        bool       $showDueDate = false,
        string     $dueDateFormat = 'd/m/Y H:i:s'
    ): string
    {
        $subject = new Fluent($cert->getCert()->data['subject']);
        $firstLine = $subject->commonName ?? $subject->organizationName;
        $issuer = new Fluent($cert->getCert()->data['issuer']);
        $secondLine = $issuer->organizationalUnitName ?? $issuer->commonName ?? $issuer->organizationName;

        $certDueDate = $showDueDate
            ? now()->createFromTimestamp(
                $cert->getCert()->data['validTo_time_t']
            )->format($dueDateFormat)
            : null;

        $callback = function ($font) use ($fontSize) {
            $font->file(__DIR__ . '/Resources/font/Roboto-Medium.ttf');
            $font->size(
                $fontSize === self::FONT_SIZE_SMALL ? 15
                    : ($fontSize === self::FONT_SIZE_MEDIUM ? 20 : 28)
            );
            $font->color('#16A085');
        };

        $selfObj = new static;

        return $selfObj
            ->setImagePath()
            ->addTextField(
                $selfObj->breakText($firstLine ?? $secondLine ?? '', $fontSize),
                160,
                80,
                $callback
            )
            ->addTextField(
                $selfObj->breakText($firstLine ? $secondLine : '', $fontSize),
                160,
                150,
                $callback
            )
            ->addTextField($certDueDate ?? '', 160, 250, $callback)
            ->generateImage();
    }


    /**
     * breakText - Insert line breaks to better fit texts
     *
     * @param string $text
     * @param string $fontSize
     * @return string
     */
    private function breakText(string $text, string $fontSize = self::FONT_SIZE_LARGE): string
    {
        $cropSize = $fontSize === self::FONT_SIZE_SMALL ? 60
            : ($fontSize === self::FONT_SIZE_MEDIUM ? 48 : 35);

        $this->previousTextBreakLine = strlen($text) >= $cropSize;

        if ($this->previousTextBreakLine) {
            $textSplit = str_split($text, ($cropSize - 3));
            $textSplit = array_map('trim', $textSplit);

            $text = join(PHP_EOL, $textSplit);
        }

        return $text;
    }

    /**
     * setImageDriver - Defines which driver will be used, GD or Imagick
     *
     * @param string $imageDriver
     *
     * @return SealImage
     * @throws InvalidImageDriverException
     *
     */
    public function setImageDriver(string $imageDriver): SealImage
    {
        if (!in_array($imageDriver, [self::IMAGE_DRIVER_GD, self::IMAGE_DRIVER_IMAGICK])) throw new InvalidImageDriverException($imageDriver);

        $this->imageDriver = $imageDriver;

        return $this;
    }

    /**
     * setImagePath - Defines the image that will be processed
     *
     * @param string|null $imagePathOrContent
     *
     * @return SealImage
     */
    public function setImagePath(string $imagePathOrContent = null): SealImage
    {
        $this->imagePathOrContent = $imagePathOrContent ?? __DIR__ . '/Resources/img/sign-seal.png';

        return $this;
    }

    /**
     * addTextField - Includes new text to be added to the image
     *
     * @param string $text
     * @param float $textX
     * @param float $textY
     * @param Closure|null $callback
     *
     * @return SealImage
     * @link http://image.intervention.io/api/text
     */
    public function addTextField(
        string  $text,
        float   $textX,
        float   $textY,
        Closure $callback = null
    ): SealImage
    {
        $newText = [
            'text' => $text,
            'x' => $textX,
            'y' => $textY,
            'callback' => $callback ?? fn() => null
        ];
        array_push($this->textFieldsDefinitions, $newText);

        return $this;
    }

    /**
     * generateImage - Return generated image
     *
     * @param string $returnType
     * @return string
     */
    public function generateImage(string $returnType = self::RETURN_IMAGE_CONTENT): string
    {
        try {
            $image = new IMG(['driver' => $this->imageDriver]);
            $image = $image->make($this->imagePathOrContent);

            foreach ($this->textFieldsDefinitions as $text) {
                /**
                 * @var float $x
                 * @var float $y
                 * @var Closure $callback
                 * @see addTextField()
                 */
                extract($text);
                $image->text($text, $x, $y, $callback);
            }

            return $returnType === self::RETURN_IMAGE_CONTENT
                ? $image->encode('png')
                : $image->encode('data-url')->encoded;
        } catch (NotReadableException $th) {
            throw $th;
        }
    }
}
