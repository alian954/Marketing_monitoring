<?php

namespace App\Services\Probe;

readonly class ProbeResult
{
    /**
     * @param  list<string>  $redirects
     * @param  list<string>  $resolvedPublicIps
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
    ) {}

    public function succeeded(): bool
    {
        return $this->errorType === null && $this->statusCode !== null && $this->statusCode >= 200 && $this->statusCode < 400;
    }
}
