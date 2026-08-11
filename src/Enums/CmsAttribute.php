<?php

namespace LSNepomuceno\LaravelA1PdfSign\Enums;

/**
 * The CMS attributes this package reads, by OID.
 *
 * Compared as dotted text rather than as encoded bytes. The DER encoding of an
 * OID can occur anywhere in a blob, including inside a certificate the CMS
 * embeds, so a comparison that only holds because the reader arrived at the
 * right node is one worth making deliberately.
 */
enum CmsAttribute: string
{
    /** id-aa-timeStampToken, RFC 3161 §3.3.2: the signature timestamp of B-T. */
    case SignatureTimestamp = '1.2.840.113549.1.9.16.2.14';

    /** id-aa-ets-revocationValues, RFC 5126 §6.3.4. */
    case RevocationValues = '1.2.840.113549.1.9.16.2.24';

    /** id-messageDigest, RFC 5652 §11.2. */
    case MessageDigest = '1.2.840.113549.1.9.4';

    /** id-signingTime, RFC 5652 §11.3. */
    case SigningTime = '1.2.840.113549.1.9.5';
}
