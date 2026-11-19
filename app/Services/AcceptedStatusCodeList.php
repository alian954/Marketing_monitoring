<?php

namespace App\Services;

use InvalidArgumentException;

class AcceptedStatusCodeList
{
    /** @return list<int> */
    public static function normalize(?string $input): array
    {
        $input = (string) $input;

        if (mb_strlen($input) > 1000) {
            throw new InvalidArgumentException('額外接受狀態碼欄位過長。');
        }

        $codes = preg_split('/[\s,]+/', trim($input), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $normalizedCodes = [];

        foreach ($codes as $code) {
            if (preg_match('/\A\d{3}\z/D', $code) !== 1 || (int) $code < 400 || (int) $code > 599) {
                throw new InvalidArgumentException('額外接受狀態碼只能是 400 至 599 的三位數 HTTP 狀態碼。');
            }

            $normalizedCodes[] = (int) $code;
        }

        $normalizedCodes = array_values(array_unique($normalizedCodes));
        sort($normalizedCodes, SORT_NUMERIC);

        return $normalizedCodes;
    }
}
