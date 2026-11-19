<?php

namespace App\Services\Probe;

class ExpectedTextMatcher
{
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
        $terms = self::terms($expectedText);

        if ($terms === []) {
            return false;
        }

        $matchedTerms = array_filter($terms, static fn (string $term): bool => self::matchesTerm($body, $term));

        return $mode === 'absent'
            ? $matchedTerms !== []
            : count($matchedTerms) === count($terms);
    }

    public static function matchesBoundedPrefix(string $body, string $expectedText, string $mode = 'present'): ?bool
    {
        $terms = self::terms($expectedText);

        if ($terms === []) {
            return null;
        }

        $matchedTerms = array_filter($terms, static fn (string $term): bool => self::matchesTerm($body, $term));

        if ($mode === 'absent') {
            return $matchedTerms === [] ? null : true;
        }

        return count($matchedTerms) === count($terms) ? true : null;
    }

    private static function matchesTerm(string $body, string $expectedText): bool
    {
        $decodedBody = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plainTextBody = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $bodyWithTagSeparators = preg_replace('/<[^>]*>/u', ' ', $body) ?? $body;
        $normalizedBody = html_entity_decode(strip_tags($bodyWithTagSeparators), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalizedBody = preg_replace('/\s+/u', ' ', trim($normalizedBody)) ?? trim($normalizedBody);
        $normalizedExpectedText = preg_replace('/\s+/u', ' ', trim($expectedText)) ?? trim($expectedText);
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
            || ($encodedExpectedText !== null && mb_stripos($body, $encodedExpectedText) !== false)
            || ($unescapedSlashExpectedText !== null && mb_stripos($body, $unescapedSlashExpectedText) !== false)
            || ($normalizedExpectedText !== '' && mb_stripos($normalizedBody, $normalizedExpectedText) !== false);
    }
}
