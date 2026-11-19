<?php

namespace App\Services\Probe;

readonly class DomainExpiryResult
{
    public function __construct(
        public ?string $domainName,
        public ?\DateTimeImmutable $expiresAt,
        public ?string $errorType,
        public ?string $errorDetail,
    ) {}

    public function succeeded(): bool
    {
        return $this->domainName !== null && $this->expiresAt !== null && $this->errorType === null;
    }
}
