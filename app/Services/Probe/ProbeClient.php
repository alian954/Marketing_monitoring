<?php

namespace App\Services\Probe;

interface ProbeClient
{
    /** @param list<string> $allowedRedirectHosts */
    public function inspect(string $url, array $allowedRedirectHosts, string $userAgent, ?string $expectedText = null, ?int $timeoutSeconds = null, string $expectedTextMode = 'present'): ProbeResult;
}
