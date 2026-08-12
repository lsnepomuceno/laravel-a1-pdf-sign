<?php

namespace LSNepomuceno\LaravelA1PdfSign\Enums;

/**
 * The DER tags a CMS and an RFC 3161 token are built from, ISO/IEC 8825-1 §8.1.2.
 *
 * Int-backed, and exempted from the string-backed arch rule by name. That rule
 * exists so a configuration file can express a case in plain text, and nothing
 * configures an ASN.1 tag: the values are fixed by the standard and are natural
 * integers (docs/decisions/0018-prefer-the-platforms-own-constructs.md).
 *
 * Only the tags this package reads are here. A tag outside the set is not an
 * error, it is simply a node nothing asks about, which is why `Asn1Node` keeps
 * the raw byte and compares through this rather than storing a case.
 */
enum Asn1Tag: int
{
    case Integer = 0x02;

    case OctetString = 0x04;

    case ObjectIdentifier = 0x06;

    case BitString = 0x03;

    case Enumerated = 0x0A;

    case UtcTime = 0x17;

    case GeneralizedTime = 0x18;

    case Sequence = 0x30;

    case Set = 0x31;

    /** Context-specific [0], constructed: the shape an optional CMS field takes. */
    case Context0 = 0xA0;

    /** Context-specific [1], constructed: unsignedAttrs in a SignerInfo. */
    case Context1 = 0xA1;
}
