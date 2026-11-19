<?php

namespace App\Services\Probe;

class ExpectedTextMatcher
{
    public const MODE_ABSENT = 'absent';

    public const MODE_PRESENT = 'present';

    public const MODE_PRESENT_ANY = 'present_any';

    public static function normalize(?string $expectedText): ?string
    {
        $terms = self::terms($expectedText);

        return $terms === [] ? null : implode("\n", $terms);
    }

    /** @return list<string> */
    public static function terms(?string $expectedText): array
    {
        if ($expectedText === null) {
            return [];
        }

        $lines = preg_split('/\R/u', $expectedText) ?: [];
        $terms = [];

        foreach ($lines as $line) {
            $term = trim($line);

            if ($term !== '' && ! in_array($term, $terms, true)) {
                $terms[] = $term;
            }
        }

        return $terms;
    }

    public static function matches(string $body, string $expectedText, string $mode = 'present'): bool
    {
        return self::evaluate($body, $expectedText, $mode)['found'] ?? false;
    }

    public static function matchesBoundedPrefix(string $body, string $expectedText, string $mode = 'present'): ?bool
    {
        return self::evaluate($body, $expectedText, $mode, true)['found'];
    }

    /**
     * @return array{found: ?bool, terms: list<array{term: string, matched: ?bool}>}
     */
    public static function evaluate(string $body, string $expectedText, string $mode = 'present', bool $isBoundedPrefix = false): array
    {
        $terms = self::terms($expectedText);

        if ($terms === []) {
            return ['found' => null, 'terms' => []];
        }

        $termMatches = array_map(
            static fn (string $term): ?bool => self::matchesTerm($body, $term) ? true : ($isBoundedPrefix ? null : false),
            $terms,
        );
        $matchedCount = count(array_filter($termMatches, static fn (?bool $matched): bool => $matched === true));
        $isAnyCondition = in_array($mode, [self::MODE_ABSENT, self::MODE_PRESENT_ANY], true);

        $found = $isBoundedPrefix
            ? ($matchedCount > 0 && $isAnyCondition ? true : ($matchedCount === count($terms) ? true : null))
            : ($isAnyCondition ? $matchedCount > 0 : $matchedCount === count($terms));

        return [
            'found' => $found,
            'terms' => array_map(
                static fn (string $term, ?bool $matched): array => ['term' => $term, 'matched' => $matched],
                $terms,
                $termMatches,
            ),
        ];
    }

    private static function matchesTerm(string $body, string $expectedText): bool
    {
        $decodedBody = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plainTextBody = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $bodyWithTagSeparators = preg_replace('/<[^>]*>/u', ' ', $body) ?? $body;
        $normalizedBody = html_entity_decode(strip_tags($bodyWithTagSeparators), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalizedBody = preg_replace('/\s+/u', ' ', trim($normalizedBody)) ?? trim($normalizedBody);
        $normalizedExpectedText = preg_replace('/\s+/u', ' ', trim($expectedText)) ?? trim($expectedText);
        $unicodeDecodedBody = html_entity_decode(
            self::decodeJsonUnicodeEscapes($body),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );
        $encodedExpectedText = json_encode($expectedText);
        $encodedExpectedText = $encodedExpectedText === false
            ? null
            : substr($encodedExpectedText, 1, -1);
        $unescapedSlashExpectedText = $encodedExpectedText === null
            ? null
            : str_replace('\\/', '/', $encodedExpectedText);

        return mb_stripos($body, $expectedText) !== false
            || mb_stripos($decodedBody, $expectedText) !== false
            || mb_stripos($plainTextBody, $expectedText) !== false
            || mb_stripos($unicodeDecodedBody, $expectedText) !== false
            || ($encodedExpectedText !== null && mb_stripos($body, $encodedExpectedText) !== false)
            || ($unescapedSlashExpectedText !== null && mb_stripos($body, $unescapedSlashExpectedText) !== false)
            || ($normalizedExpectedText !== '' && mb_stripos($normalizedBody, $normalizedExpectedText) !== false);
    }

    private static function decodeJsonUnicodeEscapes(string $body): string
    {
        return preg_replace_callback(
            '/(?<!\\\\)(?:\\\\u[0-9a-f]{4})+/i',
            static function (array $matches): string {
                $decodedText = json_decode('"'.$matches[0].'"', true);

                return is_string($decodedText) ? $decodedText : $matches[0];
            },
            $body,
        ) ?? $body;
    }
}
