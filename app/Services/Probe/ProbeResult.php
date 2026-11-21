<?php

namespace App\Services\Probe;

readonly class ProbeResult
{
    /**
     * @param  list<string>  $redirects
     * @param  list<string>  $resolvedPublicIps
     * @param  list<string>  $monitorHostIps
     * @param  list<string>|null  $monitorHostCnames
     * @param  list<array{type: 'MX'|'NS'|'TXT'|'CAA', value: string}>|null  $monitorHostDnsRecords
     * @param  list<?bool>|null  $expectedTextTermMatches
     * @param  array{meta: array<string, list<string>>|null, meta_complete: ?bool, x_robots_tag: array<string, list<string>>}|null  $responseRobotsDirectives
     * @param  array{views: ?int, likes: ?int, comments: ?int, shares: ?int, source: string}|null  $publicEngagementMetrics
     */
    public function __construct(
        public ?int $statusCode,
        public int $elapsedMs,
        public ?string $finalUrl,
        public array $redirects,
        public array $resolvedPublicIps,
        public ?string $errorType,
        public ?string $errorDetail,
        public ?bool $expectedTextFound,
        public ?\DateTimeImmutable $tlsExpiresAt,
        public array $monitorHostIps = [],
        public ?int $retryAfterSeconds = null,
        public ?bool $expectedResponseHeaderFound = null,
        public ?array $expectedTextTermMatches = null,
        public ?string $responseTitle = null,
        public ?string $responseDescription = null,
        public ?string $responseImageReferenceHash = null,
        public ?string $responseOpenGraphType = null,
        public ?string $responseCanonicalReferenceHash = null,
        public ?\DateTimeImmutable $responsePublishedAt = null,
        public ?\DateTimeImmutable $responseModifiedAt = null,
        public ?string $responseVisibleTextHash = null,
        public ?string $responseContentType = null,
        public ?int $responseBodyBytes = null,
        public ?array $monitorHostCnames = null,
        public ?array $monitorHostDnsRecords = null,
        public ?array $responseRobotsDirectives = null,
        public ?array $publicEngagementMetrics = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->errorType === null && $this->statusCode !== null && $this->statusCode >= 200 && $this->statusCode < 400;
    }
}
