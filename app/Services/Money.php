<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * All money math runs through bcmath on strings; floats never touch money.
 *
 * Internally we keep full precision with a fixed scale of 12 decimals, which
 * is comfortably beyond the 9 decimals Pixelforge reports, plus two more for
 * the exact 25% markup.
 */
class Money
{
    public const SCALE = 12;

    public static function normalize(string $value, int $scale = self::SCALE): string
    {
        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Not a decimal: {$value}");
        }

        return bcadd($value, '0', $scale);
    }

    public static function add(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcadd($a, $b, $scale);
    }

    public static function sub(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcsub($a, $b, $scale);
    }

    public static function multiply(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcmul($a, $b, $scale);
    }

    /**
     * Cost of tokens at a rate given in US dollars per 1M tokens.
     * tokens are integers, rates are decimal strings, so this is exact.
     */
    public static function tokenCost(int|string $tokens, string $ratePerMillion): string
    {
        return bcdiv(bcmul((string) $tokens, self::normalize($ratePerMillion), self::SCALE), '1000000', self::SCALE);
    }

    public static function applyMarkup(string $amount, string $markupFactor = '1.25'): string
    {
        return bcmul($amount, $markupFactor, self::SCALE);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    /**
     * Display string for the UI. We show enough decimals that a fraction of a
     * cent is still visible, but trim trailing zeros.
     */
    public static function display(string $amount, int $scale = 6): string
    {
        return self::trimZeros(self::normalize($amount, $scale));
    }

    private static function trimZeros(string $amount): string
    {
        if (! str_contains($amount, '.')) {
            return $amount;
        }

        $amount = rtrim($amount, '0');
        $amount = rtrim($amount, '.');

        return $amount === '' || $amount === '-' ? '0' : $amount;
    }
}
