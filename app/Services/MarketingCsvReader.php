<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads exported marketing CSV files whose column names vary by platform and language.
 *
 * Header cells are matched against alias lists, so a Shopify export, a SHOPLINE export and a
 * hand-made sheet can all be imported without a fixed template. Files saved as Big5 (common for
 * Excel on Traditional Chinese Windows) are converted to UTF-8 first.
 */
class MarketingCsvReader
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * @param  array<string, list<string>>  $fieldAliases  Canonical field => accepted header names (case-insensitive).
     * @return array{columns: array<string, int>, rows: list<array{line: int, cells: array<string, string>}>, truncated: bool}
     *
     * @throws MarketingCsvException
     */
    public function read(string $path, array $fieldAliases, int $maxRows): array
    {
        $contents = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);

        if ($contents === false) {
            throw new MarketingCsvException('無法讀取上傳的 CSV 檔案。');
        }

        if (strlen($contents) > self::MAX_BYTES) {
            throw new MarketingCsvException('CSV 檔案不可超過 5 MB。');
        }

        $contents = $this->toUtf8($contents);
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $firstLine = '';

        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $firstLine = $line;
                break;
            }
        }

        if ($firstLine === '') {
            throw new MarketingCsvException('CSV 檔案是空的。');
        }

        $delimiter = $this->delimiter($firstLine);
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new MarketingCsvException('無法讀取上傳的 CSV 檔案。');
        }

        fwrite($stream, $contents);
        rewind($stream);

        try {
            $header = null;
            $lineNumber = 0;

            while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
                $lineNumber++;

                if ($cells !== [null] && implode('', array_map('strval', $cells)) !== '') {
                    $header = $cells;
                    break;
                }
            }

            if ($header === null) {
                throw new MarketingCsvException('CSV 檔案是空的。');
            }

            $columns = $this->columns($header, $fieldAliases);
            $rows = [];
            $truncated = false;

            while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
                $lineNumber++;

                if ($cells === [null] || trim(implode('', array_map('strval', $cells))) === '') {
                    continue;
                }

                if (count($rows) >= $maxRows) {
                    $truncated = true;
                    break;
                }

                $mapped = [];

                foreach ($columns as $field => $index) {
                    $mapped[$field] = trim((string) ($cells[$index] ?? ''));
                }

                $rows[] = ['line' => $lineNumber, 'cells' => $mapped];
            }
        } finally {
            fclose($stream);
        }

        return ['columns' => $columns, 'rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * Parses amounts such as "NT$1,234.50", "1 234", "(120)" or "12%".
     */
    public static function number(string $value): ?float
    {
        $value = trim($value);

        if ($value === '' || $value === '-' || $value === '--') {
            return null;
        }

        $negative = str_starts_with($value, '(') && str_ends_with($value, ')');
        $normalized = preg_replace('/[^0-9.\-]/', '', str_replace(['，', ','], '', $value)) ?? '';

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        $number = (float) $normalized;

        return $negative ? -abs($number) : $number;
    }

    /**
     * Parses common export date formats into Y-m-d (Asia/Taipei for timestamps with zones).
     */
    public static function date(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $matches) === 1) {
            $value = $matches[1].'-'.$matches[2].'-'.$matches[3];
        }

        if (preg_match('/^(\d{4})[\/\-.年](\d{1,2})[\/\-.月](\d{1,2})日?(?:\s.*)?$/u', $value, $matches) === 1) {
            return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
                ? sprintf('%04d-%02d-%02d', $matches[1], $matches[2], $matches[3])
                : null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) === 1) {
            try {
                return CarbonImmutable::parse($value)->setTimezone('Asia/Taipei')->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Interprets yes/no style cells; returns null when the cell is blank or unrecognised.
     */
    public static function boolean(string $value): ?bool
    {
        $value = mb_strtolower(trim($value));

        return match (true) {
            in_array($value, ['1', 'true', 'yes', 'y', '是', '新客', '首購', 'new', 'first'], true) => true,
            in_array($value, ['0', 'false', 'no', 'n', '否', '舊客', '回購', 'returning'], true) => false,
            default => null,
        };
    }

    /**
     * @param  array<int, string|null>  $header
     * @param  array<string, list<string>>  $fieldAliases
     * @return array<string, int>
     */
    private function columns(array $header, array $fieldAliases): array
    {
        $normalizedHeader = array_map(
            static fn (mixed $cell): string => self::normalizeHeader((string) $cell),
            $header,
        );
        $columns = [];

        foreach ($fieldAliases as $field => $aliases) {
            foreach ($aliases as $alias) {
                $index = array_search(self::normalizeHeader($alias), $normalizedHeader, true);

                if ($index !== false && ! in_array($index, $columns, true)) {
                    $columns[$field] = (int) $index;
                    break;
                }
            }
        }

        return $columns;
    }

    private static function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[\s_\-]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/[（(].*[)）]$/u', '', $value) ?? $value);
    }

    private function delimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), "\t" => substr_count($line, "\t"), ';' => substr_count($line, ';')];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function toUtf8(string $contents): string
    {
        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        $converted = @mb_convert_encoding($contents, 'UTF-8', 'BIG-5');

        if (! is_string($converted) || ! mb_check_encoding($converted, 'UTF-8')) {
            throw new MarketingCsvException('CSV 檔案編碼無法辨識，請另存為 UTF-8 後再上傳。');
        }

        return $converted;
    }
}
