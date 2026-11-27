<?php

namespace App\Services;

class ProductFeatureGrant
{
    private function __construct(
        public readonly bool $isGranted,
        public readonly ?int $limitValue,
    ) {}

    public static function denied(): self
    {
        return new self(false, null);
    }

    public static function granted(?int $limitValue): self
    {
        return new self(true, $limitValue);
    }
}
