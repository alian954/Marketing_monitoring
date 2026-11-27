<?php

namespace App\Services\Probe;

/**
 * Finds marketing tracking tag identifiers embedded in a page's raw HTML.
 *
 * Only identifiers rendered in the HTML response are visible; tags injected later by a tag manager are not.
 */
final class TrackingTagExtractor
{
    public const MAX_TAGS = 20;

    public const META_PIXEL_PREFIX = 'META-';

    /** @var array<string, string> */
    private const PATTERNS = [
        'gtm' => '/\b(GTM-[A-Z0-9]{4,10})\b/',
        'ga4' => '/\b(G-[A-Z0-9]{6,12})\b/',
        'google_ads' => '/\b(AW-\d{6,12})\b/',
        'meta_pixel_init' => '/fbq\(\s*[\'"]init[\'"]\s*,\s*[\'"](\d{8,20})[\'"]/',
        'meta_pixel_image' => '/facebook\.com\/tr\/?\?(?:[^"\'\s>]*&(?:amp;)?)?id=(\d{8,20})\b/',
    ];

    /** @return list<string> */
    public static function extract(string $html): array
    {
        $tags = [];

        foreach (self::PATTERNS as $kind => $pattern) {
            if (preg_match_all($pattern, $html, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $identifier) {
                $tags[] = str_starts_with($kind, 'meta_pixel') ? self::META_PIXEL_PREFIX.$identifier : $identifier;
            }
        }

        $tags = array_values(array_unique($tags));
        sort($tags);

        return array_slice($tags, 0, self::MAX_TAGS);
    }

    /**
     * Normalize one user-entered identifier, or return null when it is not a supported tag.
     */
    public static function normalize(string $identifier): ?string
    {
        $identifier = strtoupper(trim($identifier));

        if (preg_match('/\A\d{8,20}\z/', $identifier) === 1) {
            return self::META_PIXEL_PREFIX.$identifier;
        }

        foreach (['/\AGTM-[A-Z0-9]{4,10}\z/', '/\AG-[A-Z0-9]{6,12}\z/', '/\AAW-\d{6,12}\z/', '/\AMETA-\d{8,20}\z/'] as $pattern) {
            if (preg_match($pattern, $identifier) === 1) {
                return $identifier;
            }
        }

        return null;
    }

    public static function label(string $tag): string
    {
        return match (true) {
            str_starts_with($tag, self::META_PIXEL_PREFIX) => 'Meta Pixel '.substr($tag, strlen(self::META_PIXEL_PREFIX)),
            str_starts_with($tag, 'GTM-') => 'Google Tag Manager '.$tag,
            str_starts_with($tag, 'AW-') => 'Google Ads '.$tag,
            default => 'GA4 '.$tag,
        };
    }
}
