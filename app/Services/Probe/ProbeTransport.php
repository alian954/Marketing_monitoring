<?php

namespace App\Services\Probe;

interface ProbeTransport
{
    /** @param array{url: string, host: string, port: int, ips: list<string>} $target */
    public function get(array $target, string $userAgent, int $timeoutMs): HopResponse;
}
