<?php

namespace App\Services\Probe;

readonly class RobotsTxtResult
{
    /**
     * @param  array<string, array{allowed: bool, matching_rule: ?string}>|null  $crawlerDecisions
     */
    public function __construct(
        public string $fileState,
        public ?int $httpStatus,
        public ?string $errorType,
        public ?string $policyHash,
        public ?array $crawlerDecisions,
        public ?int $ruleCount,
        public ?int $sitemapCount,
    ) {}

    public static function failed(?int $httpStatus, string $errorType): self
    {
        return new self('error', $httpStatus, $errorType, null, null, null, null);
    }
}
