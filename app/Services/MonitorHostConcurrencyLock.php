<?php

namespace App\Services;

use Illuminate\Queue\Middleware\WithoutOverlapping;

class MonitorHostConcurrencyLock
{
    public function forUrl(string $url): WithoutOverlapping
    {
        $host = parse_url(trim($url), PHP_URL_HOST);
        $normalizedHost = rtrim(mb_strtolower(trim((string) $host, '[]')), '.');
        $lockSubject = $normalizedHost === ''
            ? 'url:'.hash('sha256', trim($url))
            : 'host:'.$normalizedHost;

        return (new WithoutOverlapping('monitor-host:'.hash('sha256', $lockSubject)))
            ->releaseAfter(30)
            ->expireAfter(60)
            ->shared();
    }
}
