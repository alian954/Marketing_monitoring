<?php

namespace App\Services\Probe;

interface DetailedHostResolver extends HostResolver
{
    /**
     * @return array{ips: list<string>, cnames: list<string>}
     */
    public function resolveWithCnames(string $hostname): array;
}
