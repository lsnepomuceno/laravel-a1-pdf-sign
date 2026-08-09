<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

/**
 * Writes a cross-reference stream, ISO 32000-1 §7.5.8.
 *
 * A document whose latest section is a stream cannot be extended with a classic
 * table: a reader starting at the new startxref would find a table and follow
 * /Prev into a stream, a mixture PDF 1.5 defines only through the hybrid form of
 * §7.5.8.4. Appending a stream in turn keeps the chain in one shape, and it is
 * the shape the document already declared.
 *
 * **The stream indexes itself.** It is an ordinary object, so its own number and
 * offset belong in the table it carries, and a reader that cannot find the
 * cross-reference object in the cross-reference is entitled to reject the file.
 *
 * The trailer keyword does not appear: the stream dictionary carries /Size,
 * /Root, /Info and /Prev itself.
 *
 * See docs/decisions/0009-cross-reference-streams.md.
 *
 * @internal
 */
final readonly class XrefStreamWriter
{
    /**
     * How many bytes each of the three columns takes: type, offset, generation.
     *
     * Four bytes of offset reach 4 GB, past any document this signer will be
     * handed, and a fixed width keeps the rows addressable by multiplication.
     */
    private const array WIDTHS = [1, 4, 2];

    public function __construct(
        private XrefSubsections $subsections = new XrefSubsections(),
    ) {}

    /**
     * The complete cross-reference stream object for a revision.
     *
     * @param  array<int, int>  $offsets  Object number to byte offset, including
     *                                    this stream's own number and offset.
     * @param  int  $size  One past the highest object number in the document.
     * @param  int  $prev  Offset of the section this revision chains onto.
     */
    public function object(
        int $number,
        array $offsets,
        int $size,
        int $root,
        ?string $infoRef,
        int $prev,
    ): string {
        ksort($offsets);

        $index = '';
        $data = '';

        foreach ($this->subsections->of($offsets) as $group) {
            $index .= array_key_first($group) . ' ' . count($group) . ' ';

            foreach ($group as $offset) {
                // Type 1 is an object at a byte offset, which is all a revision
                // appends: nothing is freed and no object stream is written.
                $data .= "\x01" . pack('N', $offset) . pack('n', 0);
            }
        }

        $info = $infoRef !== null ? "/Info {$infoRef}" : '';
        $widths = implode(' ', self::WIDTHS);

        // No /Filter. A revision indexes a handful of objects, so its table is
        // tens of bytes and zlib's header and checksum would make the stream
        // larger than the bytes they compress. Leaving it raw also removes a
        // failure path, since there is no compression call that can fail.
        return "{$number} 0 obj\n"
            . '<</Type/XRef'
            . "/Size {$size}"
            . '/Index [' . trim($index) . ']'
            . "/W [{$widths}]"
            . "/Root {$root} 0 R"
            . $info
            . "/Prev {$prev}"
            . '/Length ' . strlen($data)
            . ">>\nstream\n"
            . $data
            . "\nendstream\nendobj\n";
    }
}
