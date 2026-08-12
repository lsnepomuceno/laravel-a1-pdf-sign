<?php

namespace LSNepomuceno\LaravelA1PdfSign\Enums;

/**
 * Which ICP-Brasil certificate this is, decided by the fields it carries.
 *
 * Read from the subjectAlternativeName rather than from the organizational
 * unit: the OU text varies by certification authority, and a document number
 * either is there or is not.
 */
enum IcpBrasilCertificateType: string
{
    /** e-CPF: a natural person, identified by CPF. */
    case Individual = 'individual';

    /** e-CNPJ: a company, identified by CNPJ, with a responsible person. */
    case LegalEntity = 'legal-entity';

    /**
     * Not an ICP-Brasil certificate, or not one of these two profiles.
     *
     * The third answer, on the same reasoning as
     * [0016](../../docs/decisions/0016-trust-is-the-applications-policy.md):
     * a self-signed certificate carrying no ICP-Brasil field is not a malformed
     * ICP-Brasil certificate, it is a certificate that never claimed to be one.
     */
    case None = 'none';

    public function isIcpBrasil(): bool
    {
        return $this !== self::None;
    }
}
