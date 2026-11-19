<?php

namespace App\Services\Probe;

readonly class TlsCertificateResult
{
    public function __construct(
        public ?\DateTimeImmutable $expiresAt,
        public ?string $errorType,
        public ?string $errorDetail,
    ) {}
}
