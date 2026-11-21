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
    private const MAX_ROBOTS_META_TAGS = 64;

    private const MAX_ROBOTS_DIRECTIVES_PER_GROUP = 32;

    private const MAX_ROBOTS_AGENT_GROUPS = 16;

    private const MAX_TITLE_LENGTH = 255;

    private const MAX_DESCRIPTION_LENGTH = 500;

    private const MAX_REFERENCE_LENGTH = 2_048;

    private const MAX_OPEN_GRAPH_TYPE_LENGTH = 120;

    /**
     * @return array{title: ?string, description: ?string, image_reference_hash: ?string, open_graph_type: ?string, canonical_reference_hash: ?string, published_at: ?DateTimeImmutable, modified_at: ?DateTimeImmutable}
     */
    public static function extract(string $html, ?string $responseUrl = null): array
    {
        return self::inspect($html, $responseUrl)['metadata'];
    }

    /**
     * @param  array<string, list<string>>  $responseHeaders
     * @return array{
     *     metadata: array{title: ?string, description: ?string, image_reference_hash: ?string, open_graph_type: ?string, canonical_reference_hash: ?string, published_at: ?DateTimeImmutable, modified_at: ?DateTimeImmutable},
     *     visible_text_hash: ?string,
     *     robots_directives: array{meta: array<string, list<string>>|null, meta_complete: ?bool, x_robots_tag: array<string, list<string>>},
     *     public_engagement_metrics: array{views: ?int, likes: ?int, comments: ?int, shares: ?int, source: string}|null
     * }
     */
    public static function inspect(string $html, ?string $responseUrl = null, array $responseHeaders = [], bool $bodyComplete = true): array
    {
        $robotsDirectives = self::extractRobotsDirectives(null, $html, $responseHeaders, $bodyComplete);

        if (trim($html) === '') {
            return [
                'metadata' => ['title' => null, 'description' => null, 'image_reference_hash' => null, 'open_graph_type' => null, 'canonical_reference_hash' => null, 'published_at' => null, 'modified_at' => null],
                'visible_text_hash' => hash('sha256', ''),
                'robots_directives' => $robotsDirectives,
                'public_engagement_metrics' => null,
            ];
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
            return [
                'metadata' => ['title' => null, 'description' => null, 'image_reference_hash' => null, 'open_graph_type' => null, 'canonical_reference_hash' => null, 'published_at' => null, 'modified_at' => null],
                'visible_text_hash' => null,
                'robots_directives' => $robotsDirectives,
                'public_engagement_metrics' => null,
            ];
        }

        $robotsDirectives = self::extractRobotsDirectives($document, $html, $responseHeaders, $bodyComplete);

        $pageTitle = self::normalize($document->getElementsByTagName('title')->item(0)?->textContent);
        $openGraphTitle = null;
        $twitterTitle = null;
        $openGraphDescription = null;
        $twitterDescription = null;
        $metaDescription = null;
        $imageReferenceHash = null;
        $openGraphType = null;
        $canonicalReferenceHash = null;
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
                $imageReferenceHash = self::referenceHash($content, $responseUrl);
            }

            if ($openGraphType === null && ($property === 'og:type' || $name === 'og:type')) {
                $openGraphType = self::truncate($content, self::MAX_OPEN_GRAPH_TYPE_LENGTH);
            }

            if ($canonicalReferenceHash === null && ($property === 'og:url' || $name === 'og:url')) {
                $canonicalReferenceHash = self::referenceHash($content);
            }

            if ($publishedAt === null && ($property === 'article:published_time' || $name === 'article:published_time')) {
                $publishedAt = self::parseOpenGraphDateTime($content);
            }

            if ($modifiedAt === null && ($property === 'article:modified_time' || $name === 'article:modified_time')) {
                $modifiedAt = self::parseOpenGraphDateTime($content);
            }
        }

        return [
            'metadata' => [
                'title' => self::truncate($openGraphTitle ?? $twitterTitle ?? $pageTitle, self::MAX_TITLE_LENGTH),
                'description' => self::truncate($openGraphDescription ?? $twitterDescription ?? $metaDescription, self::MAX_DESCRIPTION_LENGTH),
                'image_reference_hash' => $imageReferenceHash,
                'open_graph_type' => $openGraphType,
                'canonical_reference_hash' => $canonicalReferenceHash,
                'published_at' => $publishedAt,
                'modified_at' => $modifiedAt,
            ],
            'visible_text_hash' => self::visibleTextHash($document),
            'robots_directives' => $robotsDirectives,
            'public_engagement_metrics' => $bodyComplete
                ? SocialEngagementMetricExtractor::extract($document, $responseUrl)
                : null,
        ];
    }

    /**
     * @param  array<string, list<string>>  $responseHeaders
     * @return array{meta: array<string, list<string>>|null, meta_complete: ?bool, x_robots_tag: array<string, list<string>>}
     */
    private static function extractRobotsDirectives(?DOMDocument $document, string $html, array $responseHeaders, bool $bodyComplete): array
    {
        $contentType = mb_strtolower(trim((string) ($responseHeaders['content-type'][0] ?? '')));
        $isHtml = $contentType === '' ? null : preg_match('/\A(?:text\/html|application\/xhtml\+xml)(?:\s*;|\z)/', $contentType) === 1;
        $metaDirectives = null;
        $metaComplete = null;

        if ($isHtml !== false && $document !== null) {
            $metaDirectives = [];

            $processedRobotsMetaTags = 0;

            foreach ($document->getElementsByTagName('meta') as $meta) {
                $name = mb_strtolower(trim($meta->getAttribute('name')));
                $agent = self::robotsAgent($name);

                if ($agent === null) {
                    continue;
                }

                if ($processedRobotsMetaTags >= self::MAX_ROBOTS_META_TAGS) {
                    break;
                }

                $processedRobotsMetaTags++;

                $content = mb_substr(trim($meta->getAttribute('content')), 0, 2_048);
                $metaDirectives[$agent] = [
                    ...($metaDirectives[$agent] ?? []),
                    ...self::parseDirectiveList($content),
                ];
            }

            $metaComplete = $bodyComplete;
        } elseif ($isHtml === true && trim($html) === '') {
            $metaDirectives = [];
            $metaComplete = $bodyComplete;
        } elseif ($isHtml !== false && trim($html) !== '') {
            $metaComplete = false;
        }

        if ($metaDirectives !== null) {
            $metaDirectives = self::normalizeDirectiveGroups($metaDirectives);
        }

        return [
            'meta' => $metaDirectives,
            'meta_complete' => $metaComplete,
            'x_robots_tag' => self::parseRobotsHeaders($responseHeaders['x-robots-tag'] ?? []),
        ];
    }

    private static function robotsAgent(string $name): ?string
    {
        if ($name === 'robots') {
            return '*';
        }

        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $name) !== 1) {
            return null;
        }

        return in_array($name, ['googlebot', 'googlebot-news', 'adsbot-google', 'facebookexternalhit', 'twitterbot', 'bingbot', 'duckduckbot', 'baiduspider', 'slurp', 'yandexbot'], true)
            || str_contains($name, 'bot')
            ? $name
            : null;
    }

    /** @param list<string> $headerValues
     * @return array<string, list<string>>
     */
    private static function parseRobotsHeaders(array $headerValues): array
    {
        $directives = [];

        foreach (array_slice($headerValues, 0, 16) as $headerValue) {
            if (! is_string($headerValue) || strlen($headerValue) > 2_048) {
                continue;
            }

            $agent = '*';
            $ruleValue = $headerValue;
            $separator = strpos($headerValue, ':');

            if ($separator !== false) {
                $candidateAgent = mb_strtolower(trim(substr($headerValue, 0, $separator)));
                $candidateRules = trim(substr($headerValue, $separator + 1));

                if ($candidateRules !== '' && self::isHeaderAgent($candidateAgent)) {
                    $agent = $candidateAgent === 'robots' ? '*' : $candidateAgent;
                    $ruleValue = $candidateRules;
                }
            }

            $directives[$agent] = [
                ...($directives[$agent] ?? []),
                ...self::parseDirectiveList($ruleValue),
            ];
        }

        return self::normalizeDirectiveGroups($directives);
    }

    private static function isHeaderAgent(string $name): bool
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $name) !== 1) {
            return false;
        }

        return ! in_array($name, self::ROBOT_DIRECTIVE_NAMES, true);
    }

    /** @return list<string> */
    private static function parseDirectiveList(string $value): array
    {
        $directives = [];

        foreach (explode(',', $value) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '') {
                continue;
            }

            [$name, $parameter] = array_pad(explode(':', $candidate, 2), 2, null);
            $name = mb_strtolower(trim($name));

            if (! in_array($name, self::ROBOT_DIRECTIVE_NAMES, true)) {
                continue;
            }

            $normalized = self::normalizeDirective($name, is_string($parameter) ? trim($parameter) : null);

            if ($normalized !== null) {
                $directives[] = $normalized;
            }
        }

        return array_values(array_unique($directives));
    }

    private const ROBOT_DIRECTIVE_NAMES = [
        'all',
        'none',
        'index',
        'noindex',
        'follow',
        'nofollow',
        'noarchive',
        'nosnippet',
        'noimageindex',
        'notranslate',
        'indexifembedded',
        'max-snippet',
        'max-image-preview',
        'max-video-preview',
        'unavailable_after',
    ];

    private static function normalizeDirective(string $name, ?string $parameter): ?string
    {
        if ($parameter === null || $parameter === '' || $name === 'unavailable_after') {
            return $name;
        }

        if ($name === 'max-snippet' || $name === 'max-video-preview') {
            return preg_match('/\A(?:-1|0|[1-9][0-9]{0,5})\z/', $parameter) === 1
                ? $name.':'.$parameter
                : null;
        }

        if ($name === 'max-image-preview') {
            $parameter = mb_strtolower($parameter);

            return in_array($parameter, ['none', 'standard', 'large'], true) ? $name.':'.$parameter : null;
        }

        return $name;
    }

    /**
     * @param  array<string, list<string>>  $groups
     * @return array<string, list<string>>
     */
    private static function normalizeDirectiveGroups(array $groups): array
    {
        foreach ($groups as $agent => $directives) {
            $groups[$agent] = array_slice(array_values(array_unique($directives)), 0, self::MAX_ROBOTS_DIRECTIVES_PER_GROUP);
            sort($groups[$agent], SORT_STRING);
        }

        ksort($groups, SORT_STRING);

        return array_slice($groups, 0, self::MAX_ROBOTS_AGENT_GROUPS, true);
    }

    private static function visibleTextHash(DOMDocument $document): string
    {
        $body = $document->getElementsByTagName('body')->item(0);

        if (! $body instanceof \DOMElement) {
            return hash('sha256', '');
        }

        foreach (['script', 'style', 'template'] as $elementName) {
            $elements = $body->getElementsByTagName($elementName);

            while ($elements->length > 0) {
                $element = $elements->item(0);

                if ($element === null) {
                    break;
                }

                $element->parentNode?->removeChild($element);
            }
        }

        $text = trim($body->textContent);
        $normalizedText = preg_replace('/[\s\p{Z}]+/u', ' ', $text);

        if ($normalizedText === null) {
            $normalizedText = preg_replace('/\s+/', ' ', $text) ?? $text;
        }

        return hash('sha256', trim($normalizedText));
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

    private static function referenceHash(string $reference, ?string $responseUrl = null): ?string
    {
        if (mb_strlen($reference) > self::MAX_REFERENCE_LENGTH) {
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
