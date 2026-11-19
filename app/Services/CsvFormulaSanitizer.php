<?php

namespace App\Services;

class CsvFormulaSanitizer
{
    private const FORMULA_PREFIX_PATTERN = '/\A[\p{C}\p{Z}]*[=+@\-＝＋－＠]/u';

    public static function escape(string $value): string
    {
        return preg_match(self::FORMULA_PREFIX_PATTERN, $value) === 0
            ? $value
            : "'".$value;
    }
}
