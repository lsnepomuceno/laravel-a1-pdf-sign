<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Support;

/**
 * The check digits a CPF and a CNPJ carry.
 *
 * Laravel has no rule for either, and neither does any dependency this package
 * already has, so it is written here rather than pulled in
 * (docs/spec/conventions.md). It is one algorithm, modulus eleven over
 * positional weights, applied twice with different weights.
 *
 * **This says a number is well formed, never that it exists.** A CPF that
 * passes has the internal consistency the digits promise; whether the Receita
 * Federal ever issued it is a question only the Receita Federal answers.
 *
 * @internal
 */
final readonly class NationalRegistry
{
    /**
     * Whether eleven digits satisfy a CPF's two check digits.
     */
    public function isCpf(string $digits): bool
    {
        if (preg_match('/^\d{11}$/', $digits) !== 1) {
            return false;
        }

        // 00000000000, 11111111111 and the rest satisfy the arithmetic and are
        // rejected everywhere in Brazil, so the arithmetic is not the whole rule.
        if (preg_match('/^(\d)\1{10}$/', $digits) === 1) {
            return false;
        }

        // Weights count down from the length of the part being checked plus one.
        return $this->digit($digits, range(10, 2)) === (int) $digits[9]
            && $this->digit($digits, range(11, 2)) === (int) $digits[10];
    }

    /**
     * Whether fourteen digits satisfy a CNPJ's two check digits.
     */
    public function isCnpj(string $digits): bool
    {
        if (preg_match('/^\d{14}$/', $digits) !== 1) {
            return false;
        }

        if (preg_match('/^(\d)\1{13}$/', $digits) === 1) {
            return false;
        }

        // Unlike the CPF's, these weights cycle from 2 to 9 rather than counting
        // down, which is the only difference between the two.
        $first = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        return $this->digit($digits, $first) === (int) $digits[12]
            && $this->digit($digits, [6, ...$first]) === (int) $digits[13];
    }

    /**
     * One check digit: the weighted sum, modulus eleven, with the two remainders
     * below two both meaning zero.
     *
     * @param  list<int>  $weights  One per digit read, from the left.
     */
    private function digit(string $digits, array $weights): int
    {
        $sum = 0;

        foreach ($weights as $position => $weight) {
            $sum += (int) $digits[$position] * $weight;
        }

        $remainder = $sum % 11;

        return $remainder < 2 ? 0 : 11 - $remainder;
    }
}
