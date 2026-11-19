<?php

namespace App\Services\Probe;

use InvalidArgumentException;

class RequestHeaderParser
{
    public const MAX_HEADERS = 20;

    public const MAX_FIELD_LENGTH = 2048;

    public const MAX_TOTAL_LENGTH = 8192;

    /** @var list<string> */
    private const RESERVED_HEADERS = [
        'connection',
        'content-length',
        'expect',
        'host',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'proxy-connection',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
        'user-agent',
    ];

    /** @return array<string, string> */
    public static function parse(?string $input): array
    {
        $headers = [];
        $seenNames = [];
        $lines = preg_split('/\R/u', (string) $input);

        if ($lines === false) {
            throw new InvalidArgumentException('自訂標頭格式不是有效文字。');
        }

        foreach ($lines as $line) {
            if (preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $line) === 1) {
                throw new InvalidArgumentException('自訂標頭值不可包含控制字元。');
            }

            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $separator = strpos($line, ':');

            if ($separator === false) {
                throw new InvalidArgumentException('每個自訂標頭都必須使用「名稱: 值」格式。');
            }

            $name = trim(substr($line, 0, $separator));
            $lowerName = mb_strtolower($name);

            if (isset($seenNames[$lowerName])) {
                throw new InvalidArgumentException('同一個自訂標頭不可重複設定。');
            }

            $seenNames[$lowerName] = true;
            $headers[$name] = trim(substr($line, $separator + 1));
        }

        return self::normalize($headers);
    }

    /** @param array<array-key, mixed> $headers
     * @return array<string, string>
     */
    public static function normalize(array $headers): array
    {
        if (count($headers) > self::MAX_HEADERS) {
            throw new InvalidArgumentException('每個監測項目最多可設定 20 個自訂標頭。');
        }

        $normalized = [];
        $seenNames = [];
        $totalLength = 0;

        foreach ($headers as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                throw new InvalidArgumentException('自訂標頭名稱與值必須是文字。');
            }

            $name = trim($name);
            $value = trim($value);
            $lowerName = mb_strtolower($name);

            if (preg_match("/\\A[!#$%&'*+.^_`|~0-9A-Za-z-]+\\z/D", $name) !== 1) {
                throw new InvalidArgumentException('自訂標頭名稱格式無效。');
            }

            if (in_array($lowerName, self::RESERVED_HEADERS, true) || str_starts_with($lowerName, 'proxy-')) {
                throw new InvalidArgumentException('此標頭由安全連線設定保留，不能自訂。');
            }

            if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException('自訂標頭值不可包含控制字元。');
            }

            if (mb_strlen($name) > 128 || mb_strlen($value) > self::MAX_FIELD_LENGTH) {
                throw new InvalidArgumentException('自訂標頭名稱不得超過 128 字元，值不得超過 2048 字元。');
            }

            if (isset($seenNames[$lowerName])) {
                throw new InvalidArgumentException('同一個自訂標頭不可重複設定。');
            }

            $seenNames[$lowerName] = true;
            $totalLength += strlen($name) + strlen($value) + 4;
            $normalized[$name] = $value;
        }

        if ($totalLength > self::MAX_TOTAL_LENGTH) {
            throw new InvalidArgumentException('自訂標頭總長度不得超過 8192 個位元組。');
        }

        return $normalized;
    }

    /** @param array<array-key, mixed> $headers
     * @return list<string>
     */
    public static function toCurlLines(array $headers): array
    {
        $normalized = self::normalize($headers);
        $lines = [];

        foreach ($normalized as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        return $lines;
    }
}
