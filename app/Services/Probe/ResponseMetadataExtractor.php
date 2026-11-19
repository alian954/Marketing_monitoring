<?php

namespace App\Services\Probe;

use DOMDocument;

class ResponseMetadataExtractor
{
    private const MAX_TITLE_LENGTH = 255;

    private const MAX_DESCRIPTION_LENGTH = 500;

    /**
     * @return array{title: ?string, description: ?string}
     */
    public static function extract(string $html): array
    {
        if (trim($html) === '') {
            return ['title' => null, 'description' => null];
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousInternalErrors = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousInternalErrors);
        }

        if (! $loaded) {
            return ['title' => null, 'description' => null];
        }

        $pageTitle = self::normalize($document->getElementsByTagName('title')->item(0)?->textContent);
        $openGraphTitle = null;
        $openGraphDescription = null;
        $metaDescription = null;

        foreach ($document->getElementsByTagName('meta') as $meta) {
            $property = mb_strtolower(trim($meta->getAttribute('property')));
            $name = mb_strtolower(trim($meta->getAttribute('name')));
            $content = self::normalize($meta->getAttribute('content'));

            if ($content === null) {
                continue;
            }

            if ($openGraphTitle === null && ($property === 'og:title' || $name === 'og:title')) {
                $openGraphTitle = $content;
            }

            if ($openGraphDescription === null && ($property === 'og:description' || $name === 'og:description')) {
                $openGraphDescription = $content;
            }

            if ($metaDescription === null && $name === 'description') {
                $metaDescription = $content;
            }
        }

        return [
            'title' => self::truncate($openGraphTitle ?? $pageTitle, self::MAX_TITLE_LENGTH),
            'description' => self::truncate($openGraphDescription ?? $metaDescription, self::MAX_DESCRIPTION_LENGTH),
        ];
    }

    private static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        return $normalized === null || $normalized === '' ? null : $normalized;
    }

    private static function truncate(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
