<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;
use LSNepomuceno\LaravelA1PdfSign\Signing\Encryption\EncryptionDictionary;
use LSNepomuceno\LaravelA1PdfSign\Signing\Encryption\StandardSecurityHandler;

/**
 * Reads the cross-reference chain of an existing PDF.
 *
 * Clean-room implementation from ISO 32000-1 §7.5.4, §7.5.6 and §7.5.8.
 *
 * Both cross-reference forms are read: the classic table of §7.5.4 and the
 * cross-reference stream of §7.5.8, which PDF 1.5 introduced and most modern
 * generators emit.
 *
 * An encrypted document is opened rather than refused, when the caller supplies
 * the password that opens it. What comes back then carries the key, because
 * the revision written next has to be encrypted with the same one
 * (docs/decisions/0030-signing-a-document-that-is-encrypted.md).
 *
 * @internal
 */
final class DocumentReader
{
    public function __construct(
        private readonly XrefStreamReader $streams = new XrefStreamReader(),
        private readonly ObjectStreamReader $packed = new ObjectStreamReader(),
    ) {}

    /**
     * Walks the /Prev chain and returns the effective view of the document.
     *
     * @param  string  $password  Opens the document when it is encrypted. The
     *                            document's own, unrelated to any certificate:
     *                            one opens the file, the other unlocks a
     *                            signing key.
     *
     * @throws InvalidPdfFileException
     */
    public function read(string $pdf, #[\SensitiveParameter] string $password = ''): DocumentInfo
    {
        if (preg_match_all('/startxref\s+(\d+)\s*%%EOF/', $pdf, $matches) === 0) {
            throw new InvalidPdfFileException('no startxref pointer found; the file is not a PDF or is truncated');
        }

        $latest = (int) end($matches[1]);

        $chain = [];
        $offset = $latest;
        $seen = [];

        while ($offset > 0 && ! isset($seen[$offset])) {
            $seen[$offset] = true;
            $section = $this->readSection($pdf, $offset);
            $chain[] = $section;
            $offset = $section['prev'];
        }

        $xref = [];
        $compressed = [];
        $size = 0;
        $root = 0;
        $infoRef = null;
        $id = null;
        $usesStream = false;
        $encrypt = 0;

        // The chain runs newest to oldest, so walk it reversed and let the most
        // recent entries win.
        foreach (array_reverse($chain) as $section) {
            // The two maps are disjoint, and a revision can move an object
            // between them: signing a document whose catalog is packed writes
            // it back at the top level, so the newer entry has to evict the
            // older one rather than sit beside it
            // (docs/decisions/0015-object-streams.md).
            foreach (array_keys($section['xref']) as $number) {
                unset($compressed[$number]);
            }

            foreach (array_keys($section['compressed']) as $number) {
                unset($xref[$number]);
            }

            $compressed = array_replace($compressed, $section['compressed']);
            $xref = array_replace($xref, $section['xref']);
            $size = max($size, $section['size']);
            $usesStream = $usesStream || $section['stream'];

            if ($section['root'] > 0) {
                $root = $section['root'];
            }

            if ($section['infoRef'] !== null) {
                $infoRef = $section['infoRef'];
            }

            if ($section['id'] !== null) {
                $id = $section['id'];
            }

            if ($section['encrypt'] > 0) {
                $encrypt = $section['encrypt'];
            }
        }

        if ($root === 0) {
            throw new InvalidPdfFileException('no /Root entry found in any trailer');
        }

        $document = new DocumentInfo($xref, $size, $root, $infoRef, $latest, $usesStream, $compressed, $id);

        return $encrypt === 0
            ? $document
            : $document->encrypted($this->securityHandler($pdf, $document, $encrypt, $id, $password), $encrypt);
    }

    /**
     * The handler that holds the file encryption key.
     *
     * The encryption dictionary is the one object in the file that is never
     * itself encrypted, which is what makes this possible at all.
     *
     * A document packed into object streams is refused while encrypted: the
     * streams holding the objects are encrypted too, so reading the catalog
     * would mean decrypting on the way in as well as encrypting on the way out.
     * Refusing beats reading half of it
     * (docs/decisions/0030-signing-a-document-that-is-encrypted.md).
     *
     * @throws InvalidPdfFileException
     */
    private function securityHandler(
        string $pdf,
        DocumentInfo $document,
        int $number,
        ?string $id,
        #[\SensitiveParameter]
        string $password,
    ): StandardSecurityHandler {
        if ($document->compressed !== []) {
            throw new InvalidPdfFileException(
                'the document is encrypted and packs its objects into object streams, which this package reads but does not decrypt',
            );
        }

        $dictionary = EncryptionDictionary::parse($this->rawObject($pdf, $document, $number));

        // §7.6.4.3: the first element of /ID goes into the key for revisions up
        // to 4, so a document with no identifier cannot be opened at all there.
        preg_match('/<([0-9a-fA-F\s]*)>/', (string) $id, $first);

        return StandardSecurityHandler::open(
            $dictionary,
            $password,
            (string) hex2bin((string) preg_replace('/\s+/', '', $first[1] ?? '')),
        );
    }

    /**
     * The raw dictionary of an object, without its "N 0 obj" and "endobj".
     *
     * @throws InvalidPdfFileException
     */
    public function rawObject(string $pdf, DocumentInfo $document, int $number): string
    {
        if ($document->isCompressed($number)) {
            return $this->packedObject($pdf, $document, $number);
        }

        $offset = $document->xref[$number] ?? null;

        if ($offset === null) {
            throw new InvalidPdfFileException("object {$number} is missing from the cross-reference table");
        }

        $end = strpos($pdf, 'endobj', $offset);

        if ($end === false) {
            throw new InvalidPdfFileException("object {$number} has no endobj marker");
        }

        $raw = substr($pdf, $offset, $end - $offset);

        return rtrim((string) preg_replace('/^\s*\d+\s+\d+\s+obj\s*/', '', $raw, 1));
    }

    /**
     * The body of an object packed into an object stream, ISO 32000-1 §7.5.7.
     *
     * @throws InvalidPdfFileException
     */
    private function packedObject(string $pdf, DocumentInfo $document, int $number): string
    {
        $stream = $document->compressed[$number];
        $offset = $document->xref[$stream] ?? null;

        if ($offset === null) {
            throw new InvalidPdfFileException(
                "object {$number} is packed into object stream {$stream}, which the cross-reference table does not locate",
            );
        }

        $body = $this->packed->object($pdf, $offset, $number);

        if ($body === null) {
            throw new InvalidPdfFileException(
                "object {$number} could not be read out of object stream {$stream}",
            );
        }

        return $body;
    }

    /**
     * The document's pages, in the order a reader displays them.
     *
     * Walks the page tree from the catalog's /Pages, ISO 32000-1 §7.7.3.2,
     * because that order is the only thing that makes "page 3" mean anything.
     * Object numbers do not carry it: a producer is free to write the last page
     * first, and any generator that rewrites a page gives it a fresh number.
     *
     * Returns an empty list when the tree cannot be walked, which leaves the
     * caller to fall back rather than deciding here what a missing tree means.
     *
     * @return list<int>
     *
     * @throws InvalidPdfFileException
     */
    public function pages(string $pdf, DocumentInfo $document): array
    {
        $catalog = $this->rawObject($pdf, $document, $document->root);

        if (preg_match('/\/Pages\s+(\d+)\s+\d+\s+R/', $catalog, $root) !== 1) {
            return [];
        }

        $pages = [];
        $seen = [];

        $this->collectPages($pdf, $document, (int) $root[1], $pages, $seen);

        return $pages;
    }

    /**
     * @param  list<int>  $pages
     * @param  array<int, true>  $seen  Shared across the whole walk: a tree that
     *                                  names a node twice is malformed, and
     *                                  following it would not terminate.
     *
     * @throws InvalidPdfFileException
     */
    private function collectPages(string $pdf, DocumentInfo $document, int $number, array &$pages, array &$seen): void
    {
        if (isset($seen[$number]) || ! $document->has($number)) {
            return;
        }

        $seen[$number] = true;

        $node = $this->rawObject($pdf, $document, $number);

        // /Kids decides, not /Type. An intermediate node is required to declare
        // /Type/Pages and a leaf /Type/Page, but a node carrying kids is a node
        // to descend into whatever it calls itself.
        if (preg_match('/\/Kids\s*\[(.*?)\]/s', $node, $kids) === 1) {
            preg_match_all('/(\d+)\s+\d+\s+R/', $kids[1], $references);

            foreach ($references[1] as $reference) {
                $this->collectPages($pdf, $document, (int) $reference, $pages, $seen);
            }

            return;
        }

        if (preg_match('/\/Type\s*\/Page(?![s\w])/', $node) === 1) {
            $pages[] = $number;
        }
    }

    /**
     * How a page is displayed, against how its coordinates read.
     *
     * /Rotate and /MediaBox are both inheritable (ISO 32000-1 §7.7.3.4,
     * Table 30), and a document declared landscape once on /Pages is the common
     * case rather than the exotic one, so reading them from the page object
     * alone would miss it.
     *
     * @throws InvalidPdfFileException
     */
    public function pageGeometry(string $pdf, DocumentInfo $document, int $pageNumber): PageGeometry
    {
        $rotate = $this->inherited($pdf, $document, $pageNumber, 'Rotate');
        $mediaBox = $this->inherited($pdf, $document, $pageNumber, 'MediaBox');

        $box = null;

        if ($mediaBox !== null && preg_match_all('/-?[\d.]+/', $mediaBox, $numbers) === 4) {
            $box = [(float) $numbers[0][0], (float) $numbers[0][1], (float) $numbers[0][2], (float) $numbers[0][3]];
        }

        return PageGeometry::of((int) ($rotate ?? 0), $box);
    }

    /**
     * An inheritable page attribute, from the page or from the nearest ancestor
     * that declares it.
     *
     * @throws InvalidPdfFileException
     */
    private function inherited(string $pdf, DocumentInfo $document, int $number, string $key): ?string
    {
        $seen = [];

        while (! isset($seen[$number]) && $document->has($number)) {
            $seen[$number] = true;

            $node = $this->rawObject($pdf, $document, $number);

            if (preg_match('#/' . $key . '\s*(\[[^\]]*\]|-?[\d.]+)#', $node, $found) === 1) {
                return $found[1];
            }

            if (preg_match('/\/Parent\s+(\d+)\s+\d+\s+R/', $node, $parent) !== 1) {
                return null;
            }

            $number = (int) $parent[1];
        }

        return null;
    }

    /**
     * @throws InvalidPdfFileException
     */
    public function findFirstPage(string $pdf, DocumentInfo $document): int
    {
        $pages = $this->pages($pdf, $document);

        if ($pages !== []) {
            return $pages[0];
        }

        // Packed objects are searched too, and by their unpacked body: a page
        // dictionary is exactly the kind of object a producer packs.
        foreach ($document->compressed as $number => $stream) {
            if (preg_match('/\/Type\s*\/Page(?![s\w])/', $this->rawObject($pdf, $document, $number)) === 1) {
                return $number;
            }
        }

        foreach ($document->xref as $number => $offset) {
            if ($offset <= 0) {
                continue;
            }

            // Bounded at endobj, not at a byte count. A fixed window reaches
            // into whatever follows, and in a compact document that is the next
            // object: the catalog was reported as the first page because a
            // /Type/Page two objects later fell inside its window, and the
            // revision then wrote the signature's /AcroForm and its /Annots
            // both onto object 1, the second overwriting the first.
            $end = strpos($pdf, 'endobj', $offset);
            $object = substr($pdf, $offset, ($end === false ? strlen($pdf) : $end) - $offset);

            // The negative lookahead keeps /Pages, the page tree root, out.
            if (preg_match('/\/Type\s*\/Page(?![s\w])/', $object) === 1) {
                return $number;
            }
        }

        throw new InvalidPdfFileException('no page object found');
    }

    /**
     * @return array{xref: array<int, int>, compressed: array<int, int>, size: int, root: int, infoRef: ?string, prev: int, stream: bool, id: ?string, encrypt: int}
     *
     * @throws InvalidPdfFileException
     */
    private function readSection(string $pdf, int $offset): array
    {
        if (! str_starts_with(ltrim(substr($pdf, $offset, 32)), 'xref')) {
            // PDF 1.5 packs the same table into a stream object. Reading only
            // the classic form bounded the package to documents older tools
            // produce (docs/decisions/0009-cross-reference-streams.md).
            if ($this->streams->handles($pdf, $offset)) {
                return $this->streams->read($pdf, $offset);
            }

            throw new InvalidPdfFileException(
                "the cross-reference section at offset {$offset} is neither a table nor a stream",
            );
        }

        $position = strpos($pdf, 'xref', $offset);

        if ($position === false) {
            throw new InvalidPdfFileException("unreadable cross-reference section at offset {$offset}");
        }

        $position += 4;
        $xref = [];

        // Subsections: "<first> <count>" followed by <count> entries of exactly
        // 20 bytes each.
        while (preg_match('/\G\s*(\d+)\s+(\d+)\s*(?:\r\n|\r|\n)/', $pdf, $header, 0, $position) === 1) {
            $first = (int) $header[1];
            $count = (int) $header[2];
            $position += strlen($header[0]);

            for ($i = 0; $i < $count; $i++) {
                $entry = substr($pdf, $position + ($i * 20), 20);

                if (preg_match('/^(\d{10})\s(\d{5})\s([nf])/', $entry, $parts) === 1 && $parts[3] === 'n') {
                    $xref[$first + $i] = (int) $parts[1];
                }
            }

            $position += $count * 20;
        }

        $trailerPosition = strpos($pdf, 'trailer', $position);

        if ($trailerPosition === false) {
            throw new InvalidPdfFileException('no trailer after the cross-reference table');
        }

        $trailer = substr($pdf, $trailerPosition, 2048);

        // The reference to the encryption dictionary, when the document has
        // one. It used to be refused outright here, and now it is answered:
        // the key is derived from the caller's password and the appended
        // revision is encrypted with it
        // (docs/decisions/0030-signing-a-document-that-is-encrypted.md).
        preg_match('/\/Encrypt\s+(\d+)\s+\d+\s+R/', $trailer, $encrypt);
        preg_match('/\/Size\s+(\d+)/', $trailer, $size);
        preg_match('/\/Root\s+(\d+)\s+\d+\s+R/', $trailer, $root);
        preg_match('/\/Prev\s+(\d+)/', $trailer, $prev);
        preg_match('/\/Info\s+(\d+\s+\d+\s+R)/', $trailer, $info);
        preg_match('/\/ID\s*(\[[^\]]*\])/', $trailer, $id);

        return [
            'xref' => $xref,
            // A classic table has no way to say "packed": object streams
            // arrived with the cross-reference stream that indexes them.
            'compressed' => [],
            'size' => isset($size[1]) ? (int) $size[1] : 0,
            'root' => isset($root[1]) ? (int) $root[1] : 0,
            'infoRef' => $info[1] ?? null,
            'prev' => isset($prev[1]) ? (int) $prev[1] : 0,
            'stream' => false,
            'id' => $id[1] ?? null,
            'encrypt' => isset($encrypt[1]) ? (int) $encrypt[1] : 0,
        ];
    }
}
