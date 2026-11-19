<?php

namespace App\Services\Probe;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Throwable;

class ResponseMetadataExtractor
{
    private const MAX_TITLE_LENGTH = 255;

    private const MAX_DESCRIPTION_LENGTH = 500;

    private const MAX_IMAGE_REFERENCE_LENGTH = 2_048;

    /**
     * @return array{title: ?string, description: ?string, image_reference_hash: ?string, published_at: ?DateTimeImmutable, modified_at: ?DateTimeImmutable}
     */
    public static function extract(string $html, ?string $responseUrl = null): array
    {
        if (trim($html) === '') {
            return ['title' => null, 'description' => null, 'image_reference_hash' => null, 'published_at' => null, 'modified_at' => null];
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
            return ['title' => null, 'description' => null, 'image_reference_hash' => null, 'published_at' => null, 'modified_at' => null];
        }

        $pageTitle = self::normalize($document->getElementsByTagName('title')->item(0)?->textContent);
        $openGraphTitle = null;
        $twitterTitle = null;
        $openGraphDescription = null;
        $twitterDescription = null;
        $metaDescription = null;
        $imageReferenceHash = null;
        $publishedAt = null;
        $modifiedAt = null;

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

            if ($twitterTitle === null && ($property === 'twitter:title' || $name === 'twitter:title')) {
                $twitterTitle = $content;
            }

            if ($openGraphDescription === null && ($property === 'og:description' || $name === 'og:description')) {
                $openGraphDescription = $content;
            }

            if ($twitterDescription === null && ($property === 'twitter:description' || $name === 'twitter:description')) {
                $twitterDescription = $content;
            }

            if ($metaDescription === null && $name === 'description') {
                $metaDescription = $content;
            }

            if ($imageReferenceHash === null
                && (in_array($property, ['og:image', 'og:image:url', 'og:image:secure_url'], true)
                    || in_array($name, ['og:image', 'twitter:image', 'twitter:image:src'], true))) {
                $imageReferenceHash = self::imageReferenceHash($content, $responseUrl);
            }

            if ($publishedAt === null && ($property === 'article:published_time' || $name === 'article:published_time')) {
                $publishedAt = self::parseOpenGraphDateTime($content);
            }

            if ($modifiedAt === null && ($property === 'article:modified_time' || $name === 'article:modified_time')) {
                $modifiedAt = self::parseOpenGraphDateTime($content);
            }
        }

        return [
            'title' => self::truncate($openGraphTitle ?? $twitterTitle ?? $pageTitle, self::MAX_TITLE_LENGTH),
            'description' => self::truncate($openGraphDescription ?? $twitterDescription ?? $metaDescription, self::MAX_DESCRIPTION_LENGTH),
            'image_reference_hash' => $imageReferenceHash,
            'published_at' => $publishedAt,
            'modified_at' => $modifiedAt,
        ];
    }

    private static function parseOpenGraphDateTime(string $value): ?DateTimeImmutable
    {
        $value = trim($value);

        if (mb_strlen($value) > 80
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-](?:[01]\d|2[0-3]):?[0-5]\d)?\z/i', $value) !== 1) {
            return null;
        }

        $parsedValue = date_parse($value);

        if (($parsedValue['error_count'] ?? 1) > 0
            || ($parsedValue['warning_count'] ?? 1) > 0
            || ($parsedValue['year'] ?? 0) < 1000) {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private static function imageReferenceHash(string $reference, ?string $responseUrl): ?string
    {
        if (mb_strlen($reference) > self::MAX_IMAGE_REFERENCE_LENGTH) {
            return null;
        }

        try {
            $imageUri = new Uri($reference);

            if ($imageUri->getScheme() === '' || $imageUri->getAuthority() === '') {
                if ($responseUrl === null || trim($responseUrl) === '') {
                    return null;
                }

                $imageUri = UriResolver::resolve(new Uri($responseUrl), $imageUri);
            }
        } catch (Throwable) {
            return null;
        }

        $scheme = mb_strtolower($imageUri->getScheme());
        $host = mb_strtolower($imageUri->getHost());

        if (! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || $imageUri->getUserInfo() !== '') {
            return null;
        }

        $port = $imageUri->getPort();
        $includePort = $port !== null && ! (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443));
        $path = $imageUri->getPath() === '' ? '/' : $imageUri->getPath();
        $canonicalReference = $scheme.'://'.$host.($includePort ? ':'.$port : '').$path;

        return hash('sha256', $canonicalReference);
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
