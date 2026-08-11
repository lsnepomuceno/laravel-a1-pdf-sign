<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

use Com\Tecnick\Pdf\Sign\Output\DocTimeStamp;
use Com\Tecnick\Pdf\Sign\Timestamp\Client as TimestampClient;
use Com\Tecnick\Pdf\Sign\Timestamp\Config as TimestampConfig;
use Illuminate\Contracts\Config\Repository as Config;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessRunTimeException;
use LSNepomuceno\LaravelA1PdfSign\Signing\Cades\HttpTransport;
use Throwable;

/**
 * Appends the archive timestamp that makes a document PAdES B-LTA.
 *
 * B-LT proves the certificate was good when it was used. B-LTA proves the
 * whole file, signature and validation material together, existed at a point
 * in time attested by an authority, which is what keeps it verifiable once the
 * signing algorithms themselves age out.
 *
 * Unlike a signature timestamp, which covers only the signature bytes, this one
 * covers the entire file through its own /ByteRange, and it is a bare RFC 3161
 * token rather than a CAdES structure, hence /SubFilter /ETSI.RFC3161.
 *
 * @internal
 */
final readonly class DocTimeStampWriter
{
    /**
     * Reserved space for the token, in hex characters. A TSA token is smaller
     * than a CAdES signature, but the responder's own certificate chain rides
     * along, so this stays generous.
     */
    private const int CONTENTS_HEX_LENGTH = 16384;

    public function __construct(
        private DocumentReader $reader,
        private RevisionWriter $writer,
        private ByteRangeCalculator $byteRange,
        private HttpTransport $transport,
        private Config $config,
        private DocTimeStamp $docTimeStamp = new DocTimeStamp(),
        private SignatureFieldReader $fields = new SignatureFieldReader(new DocumentReader()),
    ) {}

    /**
     * @throws InvalidPdfFileException
     * @throws ProcessRunTimeException
     */
    public function append(string $pdf): string
    {
        $url = $this->config->get('a1-pdf-sign.signature.timestamp.url');

        if (! is_string($url) || $url === '') {
            throw new ProcessRunTimeException(
                'an archive timestamp needs a timestamp authority; set a1-pdf-sign.signature.timestamp.url',
            );
        }

        $document = $this->reader->read($pdf);

        $stampNumber = $document->size;
        $widgetNumber = $stampNumber + 1;
        $pageNumber = $this->reader->findFirstPage($pdf, $document);

        $objects = [
            $stampNumber => $this->docTimeStamp->valueObject($stampNumber, self::CONTENTS_HEX_LENGTH),
            $widgetNumber => $this->widget($widgetNumber, $stampNumber, $pageNumber, $pdf, $document),
            $document->root => $this->writer->catalogWithField($pdf, $document, $widgetNumber),
            $pageNumber => $this->writer->pageWithAnnotation($pdf, $document, $pageNumber, $widgetNumber),
        ];

        $withRevision = $this->writer->appendObjects($pdf, $document, $objects);
        $withByteRange = $this->byteRange->apply($withRevision, self::CONTENTS_HEX_LENGTH);

        return $this->embedToken($withByteRange, $url);
    }

    /**
     * @throws InvalidPdfFileException
     * @throws ProcessRunTimeException
     */
    private function embedToken(string $pdf, string $url): string
    {
        [$open, $close, $trailing] = $this->byteRange->readLast($pdf);
        $open = $this->byteRange->lastContentsOffset($pdf);

        $token = $this->requestToken(
            $this->byteRange->signableSpan($pdf, $open, $close, $trailing),
            $url,
        );

        $hex = bin2hex($token);

        if (strlen($hex) > self::CONTENTS_HEX_LENGTH) {
            throw new InvalidPdfFileException(sprintf(
                'the %d-byte timestamp token does not fit the %d-byte reserved space',
                strlen($token),
                intdiv(self::CONTENTS_HEX_LENGTH, 2),
            ));
        }

        return substr_replace(
            $pdf,
            str_pad($hex, self::CONTENTS_HEX_LENGTH, '0'),
            $open + 1,
            self::CONTENTS_HEX_LENGTH,
        );
    }

    /**
     * @throws ProcessRunTimeException
     */
    private function requestToken(string $content, string $url): string
    {
        $client = new TimestampClient(new TimestampConfig(
            host: $url,
            hashAlgorithm: $this->digestAlgorithm(),
            timeout: max(1, $this->intConfig('signature.timestamp.timeout', 20)),
        ));

        try {
            // requestToken() hashes whatever it is given, so the imprint covers
            // the file rather than a signature.
            return $client->requestToken($content, $this->transport->timestamp(
                $url,
                $this->stringConfig('signature.timestamp.username'),
                $this->stringConfig('signature.timestamp.password'),
            ));
        } catch (Throwable $exception) {
            throw new ProcessRunTimeException('archive timestamp failed: ' . $exception->getMessage());
        }
    }

    /**
     * The widget the timestamp occupies. It is never visible, but it still
     * needs a field so readers list it alongside the signatures.
     *
     * The index comes from the form's own /Fields list rather than from
     * counting "/FT /Sig" in the raw bytes. That scan undercounts a document
     * whose fields are packed into an object stream, which 2.3 made signable,
     * and two fields sharing a name is a form readers disagree about
     * (docs/decisions/0022-the-archive-timestamp-is-a-chain.md).
     *
     * @throws InvalidPdfFileException
     */
    private function widget(int $number, int $stampNumber, int $pageNumber, string $pdf, DocumentInfo $document): string
    {
        $index = count($this->fields->read($pdf, $document)) + 1;

        return "{$number} 0 obj\n"
            . '<</Type/Annot/Subtype/Widget/FT/Sig'
            . '/Rect[0 0 0 0]'
            . "/T (Timestamp{$index})"
            . "/V {$stampNumber} 0 R"
            . "/P {$pageNumber} 0 R"
            . '/F 132'
            . '/Ff 0'
            . ">>\nendobj\n";
    }

    private function digestAlgorithm(): string
    {
        $algorithm = $this->stringConfig('signature.digest_algorithm') ?? 'sha256';

        return in_array($algorithm, ['sha256', 'sha384', 'sha512'], true) ? $algorithm : 'sha256';
    }

    private function stringConfig(string $key): ?string
    {
        $value = $this->config->get("a1-pdf-sign.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intConfig(string $key, int $default): int
    {
        $value = $this->config->get("a1-pdf-sign.{$key}", $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
