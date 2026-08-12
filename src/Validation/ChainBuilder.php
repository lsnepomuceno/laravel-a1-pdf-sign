<?php

namespace LSNepomuceno\LaravelA1PdfSign\Validation;

/**
 * Orders the certificates a signature embeds into a chain, leaf first.
 *
 * A CMS carries its certificates as a set, in no particular order, so "the
 * signer" cannot be taken to be the first one. This finds the leaf and walks
 * upwards from it.
 *
 * **Each link is verified, not matched by name.** Two certificates can carry
 * identical subject and issuer names without one having signed the other, so
 * the step from child to parent is confirmed with the parent's public key.
 * Name matching alone would build a chain that looks right and proves nothing.
 *
 * Trust is a separate question and is not answered here: a chain that reaches a
 * self-signed root says the material is internally consistent, not that the
 * root is one you accept. See
 * docs/decisions/0010-validation-consumes-what-signing-writes.md.
 *
 * @internal
 */
final readonly class ChainBuilder
{
    /**
     * @param  list<string>  $pem  Every certificate available, in any order.
     * @return list<string> Leaf first, then each issuer found. Certificates that
     *                      belong to no link in the chain are left out.
     */
    public function build(array $pem): array
    {
        if ($pem === []) {
            return [];
        }

        $leaf = $this->leaf($pem);
        $chain = [$leaf];
        $remaining = array_values(array_filter($pem, static fn(string $one): bool => $one !== $leaf));

        while (($issuer = $this->issuerOf((string) end($chain), $remaining)) !== null) {
            $chain[] = $issuer;
            $remaining = array_values(array_filter($remaining, static fn(string $one): bool => $one !== $issuer));
        }

        return $chain;
    }

    /**
     * Whether the chain ends where a chain should: at a self-signed root.
     *
     * An incomplete chain is not invalid. It means the material to finish it
     * lives outside the document, which is exactly what a Document Security
     * Store exists to avoid.
     *
     * @param  list<string>  $chain
     */
    public function reachesRoot(array $chain): bool
    {
        $last = end($chain);

        return is_string($last) && $this->signed($last, $last);
    }

    /**
     * The certificate no other one in the pool was issued by.
     *
     * Falls back to the first entry when every candidate issued something,
     * which happens only in a pool with a cycle or with no leaf at all.
     *
     * @param  list<string>  $pem
     */
    private function leaf(array $pem): string
    {
        foreach ($pem as $candidate) {
            $issuedSomething = false;

            foreach ($pem as $other) {
                if ($other !== $candidate && $this->signed($other, $candidate)) {
                    $issuedSomething = true;

                    break;
                }
            }

            if (! $issuedSomething) {
                return $candidate;
            }
        }

        return $pem[0];
    }

    /**
     * @param  list<string>  $pool
     */
    private function issuerOf(string $certificate, array $pool): ?string
    {
        foreach ($pool as $candidate) {
            if ($this->signed($certificate, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether $issuer's key actually signed $subject.
     */
    private function signed(string $subject, string $issuer): bool
    {
        $key = openssl_pkey_get_public($issuer);

        if ($key === false) {
            return false;
        }

        return openssl_x509_verify($subject, $key) === 1;
    }
}
