<?php

namespace App\Services;

class PreciseDecimalFormatter
{
    public static function toFourDecimalPlaces(mixed $value): string
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || ! is_numeric($value)) {
            throw new \InvalidArgumentException('The value must be numeric.');
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new \InvalidArgumentException('The value must be finite.');
            }

            return number_format($value, 4, '.', '');
        }

        $decimalValue = (string) $value;

        if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/D', $decimalValue, $matches) !== 1) {
            $numericValue = (float) $value;

            if (! is_finite($numericValue)) {
                throw new \InvalidArgumentException('The value must be finite.');
            }

            return number_format($numericValue, 4, '.', '');
        }

        $integerPart = ltrim($matches[2], '0');
        $integerPart = $integerPart === '' ? '0' : $integerPart;
        $fractionalPart = $matches[3] ?? '';
        $roundedUnits = $integerPart.substr(str_pad($fractionalPart, 4, '0'), 0, 4);
        $roundedUnits = ltrim($roundedUnits, '0');
        $roundedUnits = $roundedUnits === '' ? '0' : $roundedUnits;

        if ((int) ($fractionalPart[4] ?? '0') >= 5) {
            $roundedUnits = self::incrementUnsignedIntegerString($roundedUnits);
        }

        $roundedUnits = str_pad($roundedUnits, 5, '0', STR_PAD_LEFT);
        $formattedValue = substr($roundedUnits, 0, -4).'.'.substr($roundedUnits, -4);
        $isNegative = $matches[1] === '-'
            && trim($integerPart.$fractionalPart, '0') !== '';

        return $isNegative ? '-'.$formattedValue : $formattedValue;
    }

    private static function incrementUnsignedIntegerString(string $value): string
    {
        for ($position = strlen($value) - 1; $position >= 0; $position--) {
            if ($value[$position] !== '9') {
                $value[$position] = (string) ((int) $value[$position] + 1);

                return $value;
            }

            $value[$position] = '0';
        }

        return '1'.$value;
    }
}
