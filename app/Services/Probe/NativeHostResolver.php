<?php

namespace App\Services\Probe;

class NativeHostResolver implements HostResolver
{
    public function resolve(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_A | DNS_AAAA);

        if ($records === false) {
            throw new ProbeException('dns_timeout', 'DNS 查詢失敗或逾時。');
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        if ($addresses === []) {
            throw new ProbeException('dns_nxdomain', '主機名稱未解析到任何 IP 位址。');
        }

        return array_values(array_unique($addresses));
    }
}
