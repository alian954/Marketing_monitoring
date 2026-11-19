<?php

namespace App\Services\Probe;

interface HeaderAwareProbeTransport extends ProbeTransport
{
    /**
     * @param  array{url: string, host: string, port: int, ips: list<string>}  $target
     * @param  array<string, string>  $requestHeaders
     */
    public function getWithHeaders(array $target, string $userAgent, int $timeoutMs, array $requestHeaders): HopResponse;
}
