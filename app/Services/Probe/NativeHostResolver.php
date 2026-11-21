<?php

namespace App\Services\Probe;

class NativeHostResolver implements DetailedHostResolver
{
    public function resolve(string $hostname): array
    {
        return $this->resolveWithCnames($hostname)['ips'];
    }

    /** @return array{ips: list<string>, cnames: list<string>} */
    public function resolveWithCnames(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_A | DNS_AAAA | DNS_CNAME);

        if ($records === false) {
            throw new ProbeException('dns_timeout', 'DNS 查詢失敗或逾時。');
        }

        $addresses = [];
        $cnames = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }

            if (strtoupper((string) ($record['type'] ?? '')) === 'CNAME' && is_string($record['target'] ?? null)) {
                $cname = mb_strtolower(rtrim(trim($record['target']), '.'));

                if ($cname !== '') {
                    $cnames[] = $cname;
                }
            }
        }

        if ($addresses === []) {
            throw new ProbeException('dns_nxdomain', '主機名稱未解析到任何 IP 位址。');
        }

        return [
            'ips' => array_values(array_unique($addresses)),
            'cnames' => array_values(array_unique($cnames)),
        ];
    }
}
