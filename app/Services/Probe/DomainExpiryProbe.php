<?php

namespace App\Services\Probe;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use JsonException;
use Throwable;

class DomainExpiryProbe
{
    private const BOOTSTRAP_URL = 'https://data.iana.org/rdap/dns.json';

    private const BOOTSTRAP_CACHE_KEY = 'monitoring.rdap.dns-bootstrap.v1';

    private const USER_AGENT = 'MarketingMonitoring/1.0 (RDAP domain expiry check)';

    private const MAX_CANDIDATES = 6;

    private const MAX_ENDPOINTS_PER_TLD = 3;

    public function __construct(
        private readonly UrlSafetyPolicy $urlSafetyPolicy,
        private readonly ProbeTransport $transport,
    ) {}

    public function inspect(string $url): DomainExpiryResult
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return $this->failure(null, 'rdap_invalid_host', '網址中沒有可查詢的網域名稱。');
        }

        try {
            $host = $this->urlSafetyPolicy->normalizeHost($host);
        } catch (ProbeException $exception) {
            return $this->failure(null, 'rdap_invalid_host', $exception->getMessage());
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->failure(null, 'rdap_unsupported', 'IP 位址沒有網域註冊期限。');
        }

        $bootstrap = $this->bootstrapServices();

        if ($bootstrap === null) {
            return $this->failure(null, 'rdap_bootstrap_unavailable', '目前無法取得 IANA 的 RDAP 服務清單。');
        }

        $labels = explode('.', $host);
        $candidateCount = min(count($labels) - 1, self::MAX_CANDIDATES);

        for ($offset = 0; $offset < $candidateCount; $offset++) {
            $candidate = implode('.', array_slice($labels, $offset));
            $tld = end($labels);
            $endpoints = $bootstrap[$tld] ?? [];

            if ($endpoints === []) {
                return $this->failure(null, 'rdap_unsupported', 'IANA 清單中沒有此頂級網域的 RDAP 服務。');
            }

            $candidateWasNotFound = false;
            $candidateHadFailure = false;

            foreach (array_slice($endpoints, 0, self::MAX_ENDPOINTS_PER_TLD) as $endpoint) {
                $response = $this->request($endpoint.'/domain/'.rawurlencode($candidate), 'application/rdap+json, application/json;q=0.9');

                if ($response->errorType !== null) {
                    $candidateHadFailure = true;

                    continue;
                }

                if ($response->statusCode === 404) {
                    $candidateWasNotFound = true;

                    continue;
                }

                if ($response->statusCode !== 200) {
                    $candidateHadFailure = true;

                    continue;
                }

                try {
                    $document = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    return $this->failure(null, 'rdap_invalid_response', 'RDAP 服務回傳的資料格式無法辨識。');
                }

                $responseDomain = is_array($document) && is_string($document['ldhName'] ?? null)
                    ? mb_strtolower(rtrim($document['ldhName'], '.'))
                    : null;

                if (
                    ! is_array($document)
                    || $responseDomain === null
                    || ($responseDomain !== $candidate && ! str_ends_with($candidate, '.'.$responseDomain))
                ) {
                    return $this->failure(null, 'rdap_domain_mismatch', 'RDAP 回傳的網域與查詢結果不一致。');
                }

                $expirationDate = $this->expirationDate($document);

                if ($expirationDate === null) {
                    return $this->failure($responseDomain, 'rdap_expiration_unavailable', 'RDAP 資料沒有提供網域到期日期。');
                }

                return new DomainExpiryResult($responseDomain, $expirationDate, null, null);
            }

            if (! $candidateWasNotFound && $candidateHadFailure) {
                return $this->failure(null, 'rdap_unavailable', 'RDAP 服務目前無法完成查詢。');
            }
        }

        return $this->failure(null, 'rdap_not_found', 'RDAP 服務找不到此網域的註冊資料。');
    }

    /** @return array<string, list<string>>|null */
    private function bootstrapServices(): ?array
    {
        $cachedServices = Cache::get(self::BOOTSTRAP_CACHE_KEY);

        if (is_array($cachedServices)) {
            return $cachedServices;
        }

        $response = $this->request(self::BOOTSTRAP_URL, 'application/json');

        if ($response->errorType !== null || $response->statusCode !== 200) {
            return null;
        }

        try {
            $document = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($document) || ! is_array($document['services'] ?? null)) {
            return null;
        }

        $services = [];

        foreach ($document['services'] as $service) {
            if (! is_array($service) || ! is_array($service[0] ?? null) || ! is_array($service[1] ?? null)) {
                continue;
            }

            $endpoints = [];

            foreach ($service[1] as $endpoint) {
                if (! is_string($endpoint) || ! $this->isSecureEndpoint($endpoint)) {
                    continue;
                }

                $endpoints[] = rtrim($endpoint, '/');
            }

            if ($endpoints === []) {
                continue;
            }

            foreach ($service[0] as $tld) {
                if (! is_string($tld) || preg_match('/\A[a-z0-9-]{1,63}\z/i', $tld) !== 1) {
                    continue;
                }

                $key = mb_strtolower($tld);
                $services[$key] = array_values(array_unique([...($services[$key] ?? []), ...$endpoints]));
            }
        }

        if ($services === []) {
            return null;
        }

        Cache::put(self::BOOTSTRAP_CACHE_KEY, $services, now()->addDay());

        return $services;
    }

    private function isSecureEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        return is_array($parts)
            && mb_strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && isset($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && (! isset($parts['port']) || (int) $parts['port'] === 443);
    }

    private function request(string $url, string $accept): HopResponse
    {
        try {
            $target = $this->urlSafetyPolicy->validateAndResolve($url);
        } catch (ProbeException $exception) {
            return new HopResponse(null, null, '', 0, $exception->type, $exception->getMessage(), null);
        }

        if (mb_strtolower((string) parse_url($target['url'], PHP_URL_SCHEME)) !== 'https') {
            return new HopResponse(null, null, '', 0, 'rdap_insecure_endpoint', 'RDAP 查詢只允許使用 HTTPS。', null);
        }

        unset($target['cnames'], $target['dns_records']);

        if ($this->transport instanceof HeaderAwareProbeTransport) {
            return $this->transport->getWithHeaders($target, self::USER_AGENT, 5000, ['Accept' => $accept]);
        }

        return $this->transport->get($target, self::USER_AGENT, 5000);
    }

    /** @param array<string, mixed> $document */
    private function expirationDate(array $document): ?DateTimeImmutable
    {
        $events = $document['events'] ?? [];

        if (! is_array($events)) {
            return null;
        }

        foreach ($events as $event) {
            if (! is_array($event) || mb_strtolower((string) ($event['eventAction'] ?? '')) !== 'expiration') {
                continue;
            }

            $eventDate = $event['eventDate'] ?? null;

            if (! is_string($eventDate) || trim($eventDate) === '') {
                continue;
            }

            try {
                return new DateTimeImmutable($eventDate, new DateTimeZone('UTC'));
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function failure(?string $domainName, string $errorType, string $errorDetail): DomainExpiryResult
    {
        return new DomainExpiryResult($domainName, null, $errorType, $errorDetail);
    }
}
