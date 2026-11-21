<?php

namespace App\Services\Probe;

use DOMDocument;
use DOMElement;
use JsonException;

class SocialEngagementMetricExtractor
{
    private const MAX_JSON_SCRIPTS = 72;

    private const MAX_JSON_BYTES = 900_000;

    private const MAX_SINGLE_JSON_BYTES = 262_144;

    private const MAX_JSON_NODES = 20_000;

    private const MAX_JSON_DEPTH = 64;

    /** @var list<string> */
    private const NON_TARGET_METRIC_CHILD_KEYS = [
        'carouselmedia',
        'children',
        'comments',
        'commentlistrenderer',
        'commentsconnection',
        'edges',
        'feedunits',
        'replies',
        'repliesconnection',
        'recommendations',
        'suggestedposts',
        'toplevelcomments',
    ];

    /** @var list<string> */
    private const METRIC_KEYS = ['views', 'likes', 'comments', 'shares'];

    /** @var list<string> */
    private const CONTENT_TYPES = [
        'article',
        'blogposting',
        'creativework',
        'discussionforumposting',
        'newsarticle',
        'socialmediaposting',
        'videoobject',
    ];

    /**
     * @return array{views: ?int, likes: ?int, comments: ?int, shares: ?int, source: string}|null
     */
    public static function extract(DOMDocument $document, ?string $responseUrl = null): ?array
    {
        $canonicalUrl = self::canonicalUrl($document) ?? $responseUrl;
        $metrics = self::emptyMetrics();
        $sources = [];
        $metaMetrics = self::extractMetaMetrics($document);
        $schemaMetrics = self::extractSchemaMetrics($document, $responseUrl, $canonicalUrl);
        $embeddedJsonMetrics = self::extractEmbeddedJsonMetrics($document, $responseUrl, $canonicalUrl);

        foreach ([
            ['metrics' => $schemaMetrics, 'source' => 'json_ld'],
            ['metrics' => $embeddedJsonMetrics, 'source' => 'embedded_json'],
            ['metrics' => $metaMetrics, 'source' => 'meta'],
        ] as $candidate) {
            foreach (self::METRIC_KEYS as $metric) {
                if ($metrics[$metric] === null && $candidate['metrics'][$metric] !== null) {
                    $metrics[$metric] = $candidate['metrics'][$metric];
                    $sources[$candidate['source']] = true;
                }
            }
        }

        if ($sources === []) {
            return null;
        }

        return $metrics + ['source' => implode('+', array_keys($sources))];
    }

    /** @return array{views: ?int, likes: ?int, comments: ?int, shares: ?int} */
    private static function extractMetaMetrics(DOMDocument $document): array
    {
        $metrics = self::emptyMetrics();

        foreach ($document->getElementsByTagName('meta') as $meta) {
            $key = mb_strtolower(trim($meta->getAttribute('property') ?: $meta->getAttribute('name')));
            $metric = match ($key) {
                'og:video:views', 'og:video:view_count', 'twitter:player:views' => 'views',
                'og:likes', 'og:like_count', 'fb:likes', 'fb:like_count' => 'likes',
                'og:comments', 'og:comment_count', 'fb:comments', 'fb:comment_count' => 'comments',
                'og:shares', 'og:share_count', 'fb:shares', 'fb:share_count' => 'shares',
                default => null,
            };

            if ($metric !== null) {
                $metrics[$metric] ??= self::parseCount($meta->getAttribute('content'));
            }
        }

        return $metrics;
    }

    /** @return array{views: ?int, likes: ?int, comments: ?int, shares: ?int} */
    private static function extractSchemaMetrics(DOMDocument $document, ?string $responseUrl, ?string $canonicalUrl): array
    {
        $candidates = [];
        $scriptCount = 0;
        $totalBytes = 0;

        foreach ($document->getElementsByTagName('script') as $script) {
            if (! $script instanceof DOMElement
                || mb_strtolower(trim(explode(';', $script->getAttribute('type'))[0])) !== 'application/ld+json') {
                continue;
            }

            if ($scriptCount >= self::MAX_JSON_SCRIPTS) {
                break;
            }

            $json = trim($script->textContent);
            $scriptCount++;
            $totalBytes += strlen($json);

            if ($json === '' || strlen($json) > self::MAX_SINGLE_JSON_BYTES || $totalBytes > self::MAX_JSON_BYTES) {
                continue;
            }

            $decoded = self::decodeJson($json);

            if ($decoded === null) {
                continue;
            }

            $visitedNodes = 0;
            $entities = [];
            self::collectSchemaEntities($decoded, 0, $visitedNodes, $entities);

            foreach ($entities as $entity) {
                if (! self::hasSupportedContentType($entity['@type'] ?? null)) {
                    continue;
                }

                $metrics = self::interactionStatistics($entity['interactionStatistic'] ?? []);

                if ($metrics !== null) {
                    $candidates[] = [
                        'metrics' => $metrics,
                        'score' => self::schemaEntityScore($entity, $responseUrl, $canonicalUrl),
                        'identity' => self::schemaEntityIdentity($entity),
                    ];
                }
            }
        }

        return self::chooseSchemaCandidate($candidates);
    }

    /** @return array{views: ?int, likes: ?int, comments: ?int, shares: ?int} */
    private static function extractEmbeddedJsonMetrics(DOMDocument $document, ?string $responseUrl, ?string $canonicalUrl): array
    {
        $targetIdentifiers = array_values(array_unique(array_filter([
            self::lastPathSegment($responseUrl),
            self::lastPathSegment($canonicalUrl),
        ], static fn (?string $identifier): bool => $identifier !== null)));

        if ($targetIdentifiers === []) {
            return self::emptyMetrics();
        }

        $candidates = [];
        $scriptCount = 0;
        $totalBytes = 0;

        foreach ($document->getElementsByTagName('script') as $script) {
            if (! $script instanceof DOMElement
                || mb_strtolower(trim(explode(';', $script->getAttribute('type'))[0])) !== 'application/json') {
                continue;
            }

            if ($scriptCount >= self::MAX_JSON_SCRIPTS) {
                break;
            }

            $json = trim($script->textContent);
            $scriptCount++;
            $totalBytes += strlen($json);

            if ($json === '' || strlen($json) > self::MAX_SINGLE_JSON_BYTES || $totalBytes > self::MAX_JSON_BYTES) {
                continue;
            }

            $decoded = self::decodeJson($json);

            if ($decoded === null) {
                continue;
            }

            $visitedNodes = 0;
            self::collectTargetMetrics($decoded, $targetIdentifiers, 0, $visitedNodes, $candidates);
        }

        $metricValues = array_fill_keys(self::METRIC_KEYS, []);

        foreach ($candidates as $candidate) {
            foreach (self::METRIC_KEYS as $metric) {
                if ($candidate[$metric] !== null) {
                    $metricValues[$metric][] = $candidate[$metric];
                }
            }
        }

        $metrics = self::emptyMetrics();

        foreach (self::METRIC_KEYS as $metric) {
            $uniqueValues = array_values(array_unique($metricValues[$metric]));

            if (count($uniqueValues) === 1) {
                $metrics[$metric] = $uniqueValues[0];
            }
        }

        return self::hasAnyMetric($metrics) ? $metrics : self::emptyMetrics();
    }

    /** @param list<array<string, mixed>> $entities */
    private static function collectSchemaEntities(mixed $value, int $depth, int &$visitedNodes, array &$entities): void
    {
        if (! is_array($value) || $depth > self::MAX_JSON_DEPTH || $visitedNodes >= self::MAX_JSON_NODES) {
            return;
        }

        $visitedNodes++;

        if (isset($value['interactionStatistic'])) {
            $entities[] = $value;
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                self::collectSchemaEntities($child, $depth + 1, $visitedNodes, $entities);
            }
        }
    }

    /** @param array<string, mixed> $entity */
    private static function hasSupportedContentType(mixed $types): bool
    {
        foreach (is_array($types) ? $types : [$types] as $type) {
            if (! is_string($type)) {
                continue;
            }

            $normalizedType = self::typeName($type);

            if (in_array($normalizedType, self::CONTENT_TYPES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{views: ?int, likes: ?int, comments: ?int, shares: ?int}|null
     */
    private static function interactionStatistics(mixed $statistics): ?array
    {
        $metrics = self::emptyMetrics();

        foreach (is_array($statistics) && array_is_list($statistics) ? $statistics : [$statistics] as $statistic) {
            if (! is_array($statistic)) {
                continue;
            }

            $metric = self::interactionMetric($statistic['interactionType'] ?? null);
            $count = self::parseCount($statistic['userInteractionCount'] ?? $statistic['count'] ?? null);

            if ($metric !== null && $count !== null) {
                $metrics[$metric] ??= $count;
            }
        }

        return self::hasAnyMetric($metrics) ? $metrics : null;
    }

    private static function interactionMetric(mixed $interactionType): ?string
    {
        $types = is_array($interactionType) ? [$interactionType['@type'] ?? null, $interactionType['name'] ?? null] : [$interactionType];

        foreach ($types as $type) {
            if (! is_string($type)) {
                continue;
            }

            $normalizedType = self::typeName($type);

            $metric = match ($normalizedType) {
                'viewaction', 'watchaction', 'playaction' => 'views',
                'likeaction', 'reactaction' => 'likes',
                'commentaction', 'replyaction' => 'comments',
                'shareaction', 'reshareaction' => 'shares',
                default => null,
            };

            if ($metric !== null) {
                return $metric;
            }
        }

        return null;
    }

    /**
     * @param  list<array{metrics: array{views: ?int, likes: ?int, comments: ?int, shares: ?int}, score: int, identity: string}>  $candidates
     * @return array{views: ?int, likes: ?int, comments: ?int, shares: ?int}
     */
    private static function chooseSchemaCandidate(array $candidates): array
    {
        if ($candidates === []) {
            return self::emptyMetrics();
        }

        $uniqueCandidates = [];

        foreach ($candidates as $candidate) {
            $uniqueCandidates[$candidate['identity']] = $candidate;
        }

        $candidates = array_values($uniqueCandidates);
        $matchedCandidates = array_values(array_filter($candidates, static fn (array $candidate): bool => $candidate['score'] > 0));

        if ($matchedCandidates !== []) {
            usort($matchedCandidates, static fn (array $first, array $second): int => $second['score'] <=> $first['score']);
            $bestScore = $matchedCandidates[0]['score'];

            return count(array_filter($matchedCandidates, static fn (array $candidate): bool => $candidate['score'] === $bestScore)) === 1
                ? $matchedCandidates[0]['metrics']
                : self::emptyMetrics();
        }

        return count($candidates) === 1 ? $candidates[0]['metrics'] : self::emptyMetrics();
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    private static function schemaEntityScore(array $entity, ?string $responseUrl, ?string $canonicalUrl): int
    {
        $entityUrls = self::schemaEntityUrls($entity);
        $canonical = self::normalizeUrl($canonicalUrl);
        $response = self::normalizeUrl($responseUrl);

        foreach ($entityUrls as $url) {
            $normalizedUrl = self::normalizeUrl($url);

            if ($canonical !== null && $normalizedUrl === $canonical) {
                return 3;
            }

            if ($response !== null && $normalizedUrl === $response) {
                return 2;
            }
        }

        return 0;
    }

    /** @param array<string, mixed> $entity */
    private static function schemaEntityIdentity(array $entity): string
    {
        return hash('sha256', json_encode([
            'type' => $entity['@type'] ?? null,
            'urls' => self::schemaEntityUrls($entity),
            'statistics' => $entity['interactionStatistic'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: serialize($entity));
    }

    /** @param array<string, mixed> $entity @return list<string> */
    private static function schemaEntityUrls(array $entity): array
    {
        $urls = [];

        foreach (['url', '@id', 'mainEntityOfPage'] as $key) {
            $value = $entity[$key] ?? null;
            $url = is_array($value) ? ($value['@id'] ?? $value['url'] ?? null) : $value;

            if (is_string($url) && trim($url) !== '') {
                $urls[] = trim($url);
            }
        }

        return array_values(array_unique($urls));
    }

    /** @return array{views: ?int, likes: ?int, comments: ?int, shares: ?int} */
    private static function canonicalUrl(DOMDocument $document): ?string
    {
        foreach ($document->getElementsByTagName('meta') as $meta) {
            if (! $meta instanceof DOMElement) {
                continue;
            }

            if (mb_strtolower(trim($meta->getAttribute('property'))) === 'og:url') {
                $url = trim($meta->getAttribute('content'));

                if ($url !== '') {
                    return $url;
                }
            }
        }

        foreach ($document->getElementsByTagName('link') as $link) {
            if ($link instanceof DOMElement && in_array('canonical', preg_split('/\s+/', mb_strtolower(trim($link->getAttribute('rel')))) ?: [], true)) {
                $url = trim($link->getAttribute('href'));

                if ($url !== '') {
                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $targetIdentifiers
     * @param  list<array{views: ?int, likes: ?int, comments: ?int, shares: ?int}>  $candidates
     */
    private static function collectTargetMetrics(mixed $value, array $targetIdentifiers, int $depth, int &$visitedNodes, array &$candidates): void
    {
        if (! is_array($value) || $depth > self::MAX_JSON_DEPTH || $visitedNodes >= self::MAX_JSON_NODES) {
            return;
        }

        $visitedNodes++;

        if (self::hasTargetIdentifier($value, $targetIdentifiers)) {
            $metricValues = array_fill_keys(self::METRIC_KEYS, []);
            self::collectTargetSubtreeMetricValues($value, $depth, $visitedNodes, $metricValues);
            $metrics = self::emptyMetrics();

            foreach (self::METRIC_KEYS as $metric) {
                $uniqueValues = array_values(array_unique($metricValues[$metric]));

                if (count($uniqueValues) === 1) {
                    $metrics[$metric] = $uniqueValues[0];
                }
            }

            if (self::hasAnyMetric($metrics)) {
                $candidates[] = $metrics;
            }

            return;
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                self::collectTargetMetrics($child, $targetIdentifiers, $depth + 1, $visitedNodes, $candidates);
            }
        }
    }

    /**
     * @param  array<string, list<int>>  $metricValues
     */
    private static function collectTargetSubtreeMetricValues(mixed $value, int $depth, int &$visitedNodes, array &$metricValues): void
    {
        if (! is_array($value) || $depth > self::MAX_JSON_DEPTH || $visitedNodes >= self::MAX_JSON_NODES) {
            return;
        }

        $visitedNodes++;
        $metrics = self::explicitMetrics($value);

        foreach (self::METRIC_KEYS as $metric) {
            if ($metrics[$metric] !== null) {
                $metricValues[$metric][] = $metrics[$metric];
            }
        }

        foreach ($value as $key => $child) {
            if (! is_array($child) || in_array(self::normalizeKey((string) $key), self::NON_TARGET_METRIC_CHILD_KEYS, true)) {
                continue;
            }

            self::collectTargetSubtreeMetricValues($child, $depth + 1, $visitedNodes, $metricValues);
        }
    }

    /** @param array<string, mixed> $value @param list<string> $targetIdentifiers */
    private static function hasTargetIdentifier(array $value, array $targetIdentifiers): bool
    {
        foreach (['shortcode', 'short_code', 'code', 'post_id', 'postid', 'media_id', 'mediaid', 'video_id', 'story_id', 'id'] as $key) {
            $identifier = $value[$key] ?? null;

            if ((! is_string($identifier) && ! is_int($identifier)) || trim((string) $identifier) === '') {
                continue;
            }

            foreach ($targetIdentifiers as $targetIdentifier) {
                if (hash_equals(mb_strtolower($targetIdentifier), mb_strtolower((string) $identifier))) {
                    return true;
                }

                if ($key === 'id' && self::decodedIdentifierContains((string) $identifier, $targetIdentifier)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function decodedIdentifierContains(string $identifier, string $targetIdentifier): bool
    {
        if (strlen($identifier) > 512 || preg_match('/\A[A-Za-z0-9+\/_=-]+\z/', $identifier) !== 1) {
            return false;
        }

        $decodedIdentifier = base64_decode($identifier, true);

        return is_string($decodedIdentifier)
            && preg_match('/(?:\A|[:\/])'.preg_quote($targetIdentifier, '/').'(?:\z|[:\/])/i', $decodedIdentifier) === 1;
    }

    /** @param array<string, mixed> $node @return array{views: ?int, likes: ?int, comments: ?int, shares: ?int} */
    private static function explicitMetrics(array $node): array
    {
        $metrics = self::emptyMetrics();
        $directKeys = [
            'viewcount' => 'views',
            'views' => 'views',
            'videoviewcount' => 'views',
            'playcount' => 'views',
            'playscount' => 'views',
            'likecount' => 'likes',
            'likescount' => 'likes',
            'likes' => 'likes',
            'favoritecount' => 'likes',
            'reactioncount' => 'likes',
            'commentcount' => 'comments',
            'commentscount' => 'comments',
            'comments' => 'comments',
            'replycount' => 'comments',
            'directreplycount' => 'comments',
            'repliescount' => 'comments',
            'replies' => 'comments',
            'totalcommentcount' => 'comments',
            'sharecount' => 'shares',
            'sharescount' => 'shares',
            'sharecountreduced' => 'shares',
            'shares' => 'shares',
            'repostcount' => 'shares',
            'resharecount' => 'shares',
        ];
        $containerKeys = [
            'edgemediapreviewlike' => 'likes',
            'edgelikedby' => 'likes',
            'edgereactions' => 'likes',
            'edgemediatocomment' => 'comments',
            'edgemediatoparentcomment' => 'comments',
            'edgethreadedcomments' => 'comments',
        ];

        foreach ($node as $key => $value) {
            $normalizedKey = self::normalizeKey((string) $key);
            $metric = $directKeys[$normalizedKey] ?? null;

            if ($metric !== null && $metrics[$metric] === null) {
                $metrics[$metric] = self::parseCount($value)
                    ?? (is_array($value) ? self::firstCount($value, ['count', 'total', 'userInteractionCount']) : null);
            }

            $containerMetric = $containerKeys[$normalizedKey] ?? null;

            if ($containerMetric !== null && $metrics[$containerMetric] === null && is_array($value)) {
                $metrics[$containerMetric] = self::firstCount($value, ['count', 'total', 'userInteractionCount']);
            }
        }

        return $metrics;
    }

    /** @param array<string, mixed> $values @param list<string> $keys */
    private static function firstCount(array $values, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                $count = self::parseCount($values[$key]);

                if ($count !== null) {
                    return $count;
                }
            }
        }

        return null;
    }

    private static function lastPathSegment(?string $url): ?string
    {
        $path = parse_url((string) $url, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
        $segment = end($segments);

        return is_string($segment) && strlen($segment) <= 128 ? $segment : null;
    }

    private static function normalizeUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $scheme = mb_strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = mb_strtolower((string) $parts['host']);
        $port = isset($parts['port']) && ! in_array([$scheme, (int) $parts['port']], [['http', 80], ['https', 443]], true)
            ? ':'.(int) $parts['port']
            : '';
        $path = rtrim((string) ($parts['path'] ?? '/'), '/');

        return $scheme.'://'.$host.$port.($path === '' ? '/' : $path);
    }

    private static function normalizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($key)) ?? '';
    }

    private static function typeName(string $type): string
    {
        $lastSeparator = strrpos($type, '/');
        $name = $lastSeparator === false ? $type : substr($type, $lastSeparator + 1);

        return mb_strtolower($name);
    }

    private static function parseCount(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $normalized = str_replace(',', '', trim((string) $value));

        if (preg_match('/\A\d{1,15}\z/', $normalized) !== 1) {
            return null;
        }

        return (int) $normalized;
    }

    /** @param list<string> $keys */
    private static function decodeJson(string $json): mixed
    {
        try {
            return json_decode($json, true, self::MAX_JSON_DEPTH, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /** @param array{views: ?int, likes: ?int, comments: ?int, shares: ?int} $metrics */
    private static function hasAnyMetric(array $metrics): bool
    {
        foreach (self::METRIC_KEYS as $metric) {
            if ($metrics[$metric] !== null) {
                return true;
            }
        }

        return false;
    }

    /** @return array{views: ?int, likes: ?int, comments: ?int, shares: ?int} */
    private static function emptyMetrics(): array
    {
        return ['views' => null, 'likes' => null, 'comments' => null, 'shares' => null];
    }
}
