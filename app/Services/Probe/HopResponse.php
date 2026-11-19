<?php

namespace App\Services\Probe;

readonly class HopResponse
{
    /** @param array<string, list<string>> $responseHeaders */
    public function __construct(
        public ?int $statusCode,
        public ?string $location,
        public string $body,
        public int $elapsedMs,
        public ?string $errorType,
        public ?string $errorDetail,
        public ?\DateTimeImmutable $tlsExpiresAt,
        public ?int $retryAfterSeconds = null,
        public array $responseHeaders = [],
    ) {}
}
