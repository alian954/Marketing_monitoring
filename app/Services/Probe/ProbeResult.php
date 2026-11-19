<?php

namespace App\Services\Probe;

readonly class ProbeResult
{
    /**
     * @param  list<string>  $redirects
     * @param  list<string>  $resolvedPublicIps
     * @param  list<string>  $monitorHostIps
     * @param  list<?bool>|null  $expectedTextTermMatches
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
        public ?\DateTimeImmutable $responsePublishedAt = null,
        public ?\DateTimeImmutable $responseModifiedAt = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->errorType === null && $this->statusCode !== null && $this->statusCode >= 200 && $this->statusCode < 400;
    }
}
