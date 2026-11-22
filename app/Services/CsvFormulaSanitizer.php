<?php

namespace App\Services;

class CsvFormulaSanitizer
{
    private const FORMULA_PREFIX_PATTERN = '/\A[\p{C}\p{Z}]*[=+@\-＝＋－＠]/u';

    public static function escape(string $value): string
    {
        if (preg_match('/\A-\d+(?:\.\d+)?\z/D', $value) === 1) {
            return $value;
        }

        return preg_match(self::FORMULA_PREFIX_PATTERN, $value) === 0
            ? $value
            : "'".$value;
    }
}
