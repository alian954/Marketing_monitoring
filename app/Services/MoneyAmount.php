<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Converts between decimal amounts entered by people and minor units (1/100) stored by the billing ledger.
 */
class MoneyAmount
{
    public const DECIMAL_PATTERN = '/\A\d{1,10}(?:\.\d{1,2})?\z/';

    public static function toMinorUnits(string $amount): int
    {
        if (preg_match(self::DECIMAL_PATTERN, $amount) !== 1) {
            throw new InvalidArgumentException('Amount must be a non-negative decimal with at most two fractional digits.');
        }

        [$wholeUnits, $fractionalUnits] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $wholeUnits * 100) + (int) str_pad($fractionalUnits, 2, '0');
    }

    /**
     * Plain decimal without grouping separators, suitable for form inputs.
     */
    public static function toDecimal(int $minorUnits): string
    {
        return intdiv($minorUnits, 100).'.'.str_pad((string) ($minorUnits % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function format(int $minorUnits, ?string $currencyCode = null): string
    {
        $formatted = number_format(intdiv($minorUnits, 100)).'.'.str_pad((string) ($minorUnits % 100), 2, '0', STR_PAD_LEFT);

        return $currencyCode === null ? $formatted : $currencyCode.' '.$formatted;
    }
}
