<?php

namespace App\Services\Probe;

interface DcardBrowserRenderer
{
    /** @param array{url: string, host: string, port: int, ips: list<string>, cnames: list<string>|null, dns_records: list<array{type: 'MX'|'NS'|'TXT'|'CAA', value: string}>|null} $target */
    public function render(array $target, int $timeoutMilliseconds): ?HopResponse;
}
