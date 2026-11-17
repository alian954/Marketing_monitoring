<?php

namespace App\Services\Probe;

interface HostResolver
{
    /** @return list<string> */
    public function resolve(string $hostname): array;
}
