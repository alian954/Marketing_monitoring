<?php

namespace App\Services\Probe;

class UrlSafetyPolicy
{
    /** @var list<list<string>> */
    private const SOCIAL_REDIRECT_HOST_GROUPS = [
        ['facebook.com', 'fb.watch'],
        ['instagram.com', 'instagr.am'],
        ['threads.com', 'threads.net'],
    ];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @param  list<string>  $allowedRedirectHosts
     * @return array{url: string, host: string, port: int, ips: list<string>, cnames: list<string>|null, dns_records: list<array{type: 'MX'|'NS'|'TXT'|'CAA', value: string}>|null}
     */
    public function validateAndResolve(string $url, array $allowedRedirectHosts = [], ?string $originHost = null, ?int $dnsTimeoutMilliseconds = null): array
    {
        $target = $this->normalizeTarget($url, $allowedRedirectHosts, $originHost);
        $host = $target['host'];

        $cnames = null;
        $dnsRecords = null;

        if ($target['is_literal_ip']) {
            $ips = [$host];
        } elseif ($this->resolver instanceof DetailedHostResolver) {
            $resolution = $this->resolver->resolveDetails($host, $dnsTimeoutMilliseconds);
            $ips = $resolution['ips'];
            $cnames = $resolution['cnames'];
            $dnsRecords = $resolution['records'];
        } else {
            $ips = $this->resolver->resolve($host);
        }

        if ($ips === []) {
            throw new ProbeException('dns_no_address', 'DNS 回應沒有可用的 A/AAAA 位址，可能是名稱不存在、沒有地址記錄，或解析器未提供回答。');
        }

        $ips = array_values(array_unique($ips));

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw new ProbeException('blocked_ssrf', '主機名稱解析到私有、保留或特殊用途 IP 位址，已封鎖檢查。');
            }
        }

        return [
            'url' => $target['url'],
            'host' => $host,
            'port' => $target['port'],
            'ips' => $ips,
            'cnames' => $cnames,
            'dns_records' => $dnsRecords,
        ];
    }

    public function normalizeUrl(string $url, array $allowedRedirectHosts = [], ?string $originHost = null): string
    {
        return $this->normalizeTarget($url, $allowedRedirectHosts, $originHost)['url'];
    }

    /**
     * @param  list<string>  $allowedRedirectHosts
     * @return array{url: string, host: string, port: int, is_literal_ip: bool}
     */
    private function normalizeTarget(string $url, array $allowedRedirectHosts, ?string $originHost): array
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new ProbeException('invalid_url', '請輸入不含帳號密碼的完整 HTTP 或 HTTPS 網址。');
        }

        $scheme = mb_strtolower((string) $parts['scheme']);
        $host = $this->normalizeHost((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if (! in_array($scheme, ['http', 'https'], true) || $port !== ($scheme === 'https' ? 443 : 80)) {
            throw new ProbeException('blocked_port', '僅允許使用 HTTP 連接埠 80 或 HTTPS 連接埠 443。');
        }

        $isLiteralIp = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if ($isLiteralIp && ! $this->isPublicIp($host)) {
            throw new ProbeException('blocked_ssrf', '目的 IP 為私有、保留或特殊用途位址，已封鎖檢查。');
        }

        if ($originHost !== null && $host !== $originHost) {
            $allowlist = array_map(fn (string $allowed): string => $this->normalizeHost($allowed), $allowedRedirectHosts);

            if (! in_array($host, $allowlist, true) && ! $this->isAllowedSocialPlatformRedirect($originHost, $host)) {
                throw new ProbeException('redirect_host_not_allowed', '重新導向主機未列入此監測項目的允許清單。');
            }
        }

        $path = (string) ($parts['path'] ?? '/');
        $normalizedUrl = $scheme.'://'.$this->formatHostForUrl($host).($path === '' ? '/' : $path);

        if (isset($parts['query'])) {
            $normalizedUrl .= '?'.$parts['query'];
        }

        return ['url' => $normalizedUrl, 'host' => $host, 'port' => $port, 'is_literal_ip' => $isLiteralIp];
    }

    public function normalizeHost(string $host): string
    {
        $host = mb_strtolower(trim($host, " .\t\n\r\0\x0B[]"));

        if ($host === '' || str_contains($host, '%')) {
            throw new ProbeException('invalid_host', '主機名稱格式無效。');
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }

        if (preg_match('/[^\x20-\x7E]/', $host) === 1) {
            if (! function_exists('idn_to_ascii')) {
                throw new ProbeException('invalid_host', '國際化網域名稱需要 PHP intl 擴充功能。');
            }

            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
        }

        if ($host === '' || strlen($host) > 253 || str_contains($host, '.') === false || preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $host) !== 1) {
            throw new ProbeException('invalid_host', '請輸入完整的公開網域名稱。');
        }

        return $host;
    }

    /** @return list<string> */
    public function normalizeRedirectHosts(?string $hosts): array
    {
        $hosts = (string) $hosts;

        if (mb_strlen($hosts) > 2000) {
            throw new ProbeException('invalid_redirect_hosts', '重新導向主機清單不得超過 2000 個字元。');
        }

        $normalizedHosts = [];

        foreach (preg_split('/[\r\n,]+/', $hosts, flags: PREG_SPLIT_NO_EMPTY) ?: [] as $host) {
            $normalizedHosts[] = $this->normalizeHost($host);
        }

        return array_values(array_unique($normalizedHosts));
    }

    private function isPublicIp(string $ip): bool
    {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        $packed = @inet_pton($ip);

        if ($packed === false || filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            return false;
        }

        $blockedRanges = strlen($packed) === 4 ? [
            '0.0.0.0/8',
            '10.0.0.0/8',
            '100.64.0.0/10',
            '127.0.0.0/8',
            '169.254.0.0/16',
            '172.16.0.0/12',
            '192.0.0.0/24',
            '192.0.2.0/24',
            '192.31.196.0/24',
            '192.52.193.0/24',
            '192.88.99.0/24',
            '192.168.0.0/16',
            '192.175.48.0/24',
            '198.18.0.0/15',
            '198.51.100.0/24',
            '203.0.113.0/24',
            '224.0.0.0/4',
            '240.0.0.0/4',
            '255.255.255.255/32',
        ] : [
            '::/128',
            '::1/128',
            '::ffff:0:0/96',
            '64:ff9b::/96',
            '64:ff9b:1::/48',
            '100::/64',
            '100:0:0:1::/64',
            '2001::/23',
            '2001:db8::/32',
            '2002::/16',
            '3fff::/20',
            '5f00::/16',
            'fc00::/7',
            'fe80::/10',
            'ff00::/8',
        ];

        foreach ($blockedRanges as $range) {
            if ($this->matchesCidr($packed, $range)) {
                return false;
            }
        }

        return true;
    }

    private function matchesCidr(string $packedIp, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr, 2);
        $packedNetwork = inet_pton($network);
        $bitCount = (int) $bits;
        $wholeBytes = intdiv($bitCount, 8);
        $remainingBits = $bitCount % 8;

        if (substr($packedIp, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits) & 0xFF;

        return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
    }

    private function formatHostForUrl(string $host): string
    {
        return str_contains($host, ':') ? '['.$host.']' : $host;
    }

    private function isAllowedSocialPlatformRedirect(string $originHost, string $redirectHost): bool
    {
        foreach (self::SOCIAL_REDIRECT_HOST_GROUPS as $hostGroup) {
            $originBelongsToGroup = false;

            foreach ($hostGroup as $domain) {
                if ($originHost === $domain || str_ends_with($originHost, '.'.$domain)) {
                    $originBelongsToGroup = true;
                    break;
                }
            }

            if (! $originBelongsToGroup) {
                continue;
            }

            foreach ($hostGroup as $domain) {
                if ($redirectHost === $domain || str_ends_with($redirectHost, '.'.$domain)) {
                    return true;
                }
            }
        }

        return false;
    }
}
