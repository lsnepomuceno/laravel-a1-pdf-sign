<?php

namespace LSNepomuceno\LaravelA1PdfSign\Sign;

use Closure;
use Illuminate\Support\Fluent;
use Intervention\Image\Drivers\AbstractDriver;
use Intervention\Image\Drivers\Gd\Driver as GDDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager as IMG;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\{InvalidImageDriverException};

class SealImage
{
    private string $imagePathOrContent;

    private AbstractDriver $imageDriver;

    private array $textFieldsDefinitions = [];

    private bool $previousTextBreakLine = false;

    const IMAGE_DRIVER_GD = 'gd';
    const IMAGE_DRIVER_IMAGICK = 'imagick';
    const FONT_SIZE_SMALL = 'FONT_SIZE_SMALL';
    const FONT_SIZE_MEDIUM = 'FONT_SIZE_MEDIUM';
    const FONT_SIZE_LARGE = 'FONT_SIZE_LARGE';
    const RETURN_IMAGE_CONTENT = 'RETURN_IMAGE_CONTENT';
    const RETURN_BASE64 = 'RETURN_BASE64';

    /**
     * @throws InvalidImageDriverException
     */
    public function __construct(AbstractDriver $imageDriver = new GDDriver)
    {
        $this->setImageDriver($imageDriver);
    }

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
            ? now()
                ->createFromTimestamp(
                    $cert->getCert()->data['validTo_time_t']
                )->format($dueDateFormat)
            : null;

        $callback = function ($font) use ($fontSize) {
            $font->file(dirname(__DIR__) . '/Resources/font/Roboto-Medium.ttf');

            $size = match ($fontSize) {
                self::FONT_SIZE_SMALL => 15,
                self::FONT_SIZE_MEDIUM => 20,
                default => 28
            };

            $font->size($size);
            $font->color('#16A085');
        };

        $selfObj = new static;

        return $selfObj
            ->setImagePath()
            ->addTextField(
                text: $selfObj->breakText($firstLine ?? $secondLine ?? '', $fontSize),
                textX: 160,
                textY: 80,
                callback: $callback
            )
            ->addTextField(
                text: $selfObj->breakText($firstLine ? $secondLine : '', $fontSize),
                textX: 160,
                textY: 150,
                callback: $callback
            )
            ->addTextField(
                text: $certDueDate ?? '',
                textX: 160,
                textY: 250,
                callback: $callback)
            ->generateImage();
    }

    private function breakText(string $text, string $fontSize = self::FONT_SIZE_LARGE): string
    {
        $cropSize = match ($fontSize) {
            self::FONT_SIZE_SMALL => 60,
            self::FONT_SIZE_MEDIUM => 48,
            default => 35
        };

        $this->previousTextBreakLine = strlen($text) >= $cropSize;

        if ($this->previousTextBreakLine) {
            $textSplit = str_split(string: $text, length: ($cropSize - 3));
            $textSplit = array_map(callback: 'trim', array: $textSplit);
            $text = join(separator: PHP_EOL, array: $textSplit);
        }

        return $text;
    }

    /**
     * @throws InvalidImageDriverException
     */
    public function setImageDriver(AbstractDriver $imageDriver): self
    {
        if (!in_array($imageDriver::class, [GDDriver::class, ImagickDriver::class])) {
            throw new InvalidImageDriverException($imageDriver::class);
        }

        $this->imageDriver = $imageDriver;

        return $this;
    }

    public function setImagePath(string $imagePathOrContent = null): self
    {
        $this->imagePathOrContent = $imagePathOrContent ?? dirname(__DIR__) . '/Resources/img/sign-seal.png';

        return $this;
    }

    /**
     * @link http://image.intervention.io/api/text
     */
    public function addTextField(
        string  $text,
        float   $textX,
        float   $textY,
        Closure $callback = null
    ): self
    {
        $newText = [
            'text' => $text,
            'x' => $textX,
            'y' => $textY,
            'callback' => $callback ?? fn() => null
        ];

        $this->textFieldsDefinitions[] = $newText;

        return $this;
    }

    /**
     * @throw \Intervention\Image\ImageManager\Exception\NotReadableException
     */
    public function generateImage(string $returnType = self::RETURN_IMAGE_CONTENT): string
    {
        $image = new IMG(driver: $this->imageDriver);
        $image = $image->read($this->imagePathOrContent);

        foreach ($this->textFieldsDefinitions as $text) {
            ['text' => $text, 'x' => $x, 'y' => $y, 'callback' => $callback] = $text;
            $image->text($text, $x, $y, $callback);
        }

        if ($returnType === self::RETURN_IMAGE_CONTENT) {
            return $image->encode(encoder: new JpegEncoder)->toString();
        }

        return $image->encode(encoder: new JpegEncoder)->toDataUri();
    }
}
