<?php

namespace App\Services\Probe;

interface RobotsTxtProbe
{
    /** @param list<string> $allowedRedirectHosts */
    public function inspect(string $url, array $allowedRedirectHosts = []): RobotsTxtResult;
}
