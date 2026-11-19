<?php

namespace App\Services\Probe;

use InvalidArgumentException;

class ResponseHeaderCondition
{
    /** @return array{name: string, value: string}|null */
    public static function parse(?string $condition): ?array
    {
        $condition = (string) $condition;

        if ($condition === '') {
            return null;
        }

        if (! mb_check_encoding($condition, 'UTF-8')) {
            throw new InvalidArgumentException('回應標頭條件必須使用有效的 UTF-8 文字。');
        }

        if (preg_match('/\R/u', $condition) === 1 || preg_match('/[\x00-\x1F\x7F]/', $condition) === 1) {
            throw new InvalidArgumentException('回應標頭條件只能包含一行文字，且不可含控制字元。');
        }

        $condition = trim($condition);

        if ($condition === '') {
            return null;
        }

        $separator = strpos($condition, ':');

        if ($separator === false) {
            throw new InvalidArgumentException('請使用「標頭名稱: 預期值」格式；只檢查存在時可留空預期值。');
        }

        $name = trim(substr($condition, 0, $separator));
        $value = trim(substr($condition, $separator + 1));

        if (preg_match("/\\A[!#$%&'*+.^_`|~0-9A-Za-z-]+\\z/D", $name) !== 1) {
            throw new InvalidArgumentException('回應標頭名稱格式無效。');
        }

        if (mb_strlen($name) > 128 || mb_strlen($value) > 1024 || strlen($condition) > 1200) {
            throw new InvalidArgumentException('回應標頭名稱不得超過 128 字元，預期值不得超過 1024 字元。');
        }

        return ['name' => $name, 'value' => $value];
    }

    public static function normalizedCondition(?string $condition): ?string
    {
        $parsed = self::parse($condition);

        return $parsed === null ? null : $parsed['name'].': '.$parsed['value'];
    }

    public static function headerName(?string $condition): ?string
    {
        try {
            $parsed = self::parse($condition);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $parsed === null ? null : $parsed['name'];
    }

    /** @param array<string, list<string>> $responseHeaders */
    public static function matches(array $responseHeaders, ?string $condition): ?bool
    {
        $parsed = self::parse($condition);

        if ($parsed === null) {
            return null;
        }

        $values = $responseHeaders[mb_strtolower($parsed['name'])] ?? [];

        if ($values === []) {
            return false;
        }

        if ($parsed['value'] === '') {
            return true;
        }

        foreach ($values as $value) {
            if (mb_stripos($value, $parsed['value']) !== false) {
                return true;
            }
        }

        return false;
    }
}
