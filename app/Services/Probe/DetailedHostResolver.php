<?php

namespace App\Services\Probe;

interface DetailedHostResolver extends HostResolver
{
    /**
     * @return array{ips: list<string>, cnames: list<string>, records: list<array{type: 'MX'|'NS'|'TXT'|'CAA', value: string}>}
     */
    public function resolveDetails(string $hostname, ?int $timeoutMilliseconds = null): array;
}
