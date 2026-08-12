<?php

namespace LSNepomuceno\LaravelA1PdfSign\Certificates;

use LSNepomuceno\LaravelA1PdfSign\Enums\Asn1Tag;
use LSNepomuceno\LaravelA1PdfSign\Support\Pem;
use LSNepomuceno\LaravelA1PdfSign\Validation\Asn1Node;
use LSNepomuceno\LaravelA1PdfSign\Validation\Asn1Reader;

/**
 * The `otherName` entries in a certificate's subjectAlternativeName.
 *
 * **`openssl_x509_parse()` cannot answer this.** It renders the extension as
 * text, and an `otherName` under an OID it does not know comes back as
 * `othername:<unsupported>`, which is where every ICP-Brasil field lives. So
 * the extension is walked here, in the DER, with the reader
 * [0019](../../docs/decisions/0019-validation-reads-what-it-writes.md) built
 * for the CMS.
 *
 * RFC 5280 §4.2.1.6:
 *
 * ```
 * GeneralName ::= CHOICE { otherName [0] OtherName, ... }
 * OtherName   ::= SEQUENCE { type-id OBJECT IDENTIFIER, value [0] EXPLICIT ANY }
 * ```
 *
 * @internal
 */
final readonly class SubjectAlternativeNameReader
{
    /** RFC 5280 §4.2.1.6. */
    private const string EXTENSION_OID = '2.5.29.17';

    public function __construct(private Asn1Reader $reader = new Asn1Reader()) {}

    /**
     * Every otherName the certificate carries, as OID to written value.
     *
     * @return array<string, string>
     */
    public function otherNames(string $certificate): array
    {
        // A PEM bundle carries the private key too, and often first, so the
        // certificate is taken by kind rather than by position. Handing the
        // whole bundle to a DER decoder reads the key and finds no extensions,
        // which looks exactly like a certificate that has none.
        $der = Pem::hasCertificate($certificate)
            ? Pem::toDer(Pem::certificates($certificate)[0] ?? '')
            : $certificate;

        if ($der === null || $der === '') {
            return [];
        }

        $names = $this->generalNames($der);

        if ($names === null) {
            return [];
        }

        $found = [];

        foreach ($this->reader->childrenOf($der, $names) as $entry) {
            // Context tag [0], constructed: the otherName arm of the CHOICE.
            // Every other arm is a name shape this does not read.
            if (! $entry->is(Asn1Tag::Context0)) {
                continue;
            }

            $parts = $this->reader->childrenOf($der, $entry);
            $oid = $this->reader->oid($der, $parts[0] ?? null);

            if ($oid === null || ! isset($parts[1])) {
                continue;
            }

            $value = $this->value($der, $parts[1]);

            if ($value !== null) {
                $found[$oid] = $value;
            }
        }

        return $found;
    }

    /**
     * The GeneralNames sequence inside the extension, or null when the
     * certificate carries no subjectAlternativeName.
     */
    private function generalNames(string $der): ?Asn1Node
    {
        $certificate = $this->reader->at($der);

        if ($certificate === null) {
            return null;
        }

        // Certificate ::= SEQUENCE { tbsCertificate, ... }, and the extensions
        // are tbsCertificate's [3], which is the last field and optional.
        $tbs = $this->reader->path($der, $certificate, [0]);

        if ($tbs === null) {
            return null;
        }

        foreach ($this->reader->childrenOf($der, $tbs) as $field) {
            if (! $field->is(Asn1Tag::Context3)) {
                continue;
            }

            return $this->extensionValue($der, $field);
        }

        return null;
    }

    /**
     * Walks the extension list for the subjectAlternativeName and opens the
     * OCTET STRING that wraps its value.
     */
    private function extensionValue(string $der, Asn1Node $extensions): ?Asn1Node
    {
        $list = $this->reader->path($der, $extensions, [0]);

        if ($list === null) {
            return null;
        }

        foreach ($this->reader->childrenOf($der, $list) as $extension) {
            $parts = $this->reader->childrenOf($der, $extension);

            if ($this->reader->oid($der, $parts[0] ?? null) !== self::EXTENSION_OID) {
                continue;
            }

            // The critical flag is optional and shifts the value along by one,
            // so the wrapper is the last child rather than the second.
            $wrapper = end($parts);

            if ($wrapper === false || ! $wrapper->is(Asn1Tag::OctetString)) {
                return null;
            }

            return $this->reader->at($der, $wrapper->contentOffset());
        }

        return null;
    }

    /**
     * The string inside `value [0] EXPLICIT ANY`.
     *
     * The specification says OCTET STRING, and real certificates also use
     * UTF8String, PrintableString and IA5String. The tag is not the point: the
     * content is a fixed-width string either way, so whatever primitive the
     * wrapper holds is read as one.
     */
    private function value(string $der, Asn1Node $wrapper): ?string
    {
        $inner = $this->reader->at($der, $wrapper->contentOffset());

        if ($inner === null || $inner->end() > $wrapper->end() || $inner->isConstructed()) {
            return null;
        }

        return $inner->content($der);
    }
}
