<?php

namespace App\Services\Probe;

use DateTimeImmutable;
use DateTimeZone;

class CurlProbeTransport implements ProbeTransport
{
    public function __construct(private readonly RetryAfterParser $retryAfterParser) {}

    public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
    {
        $handle = curl_init($target['url']);

        if ($handle === false) {
            return new HopResponse(null, null, '', 0, 'curl_initialization_failed', '安全連線用戶端無法初始化。', null);
        }

        $bodyBuffer = new BoundedResponseBodyBuffer((int) config('monitoring.max_body_bytes'));
        $location = null;
        $retryAfterHeader = null;
        $retryAfterParser = $this->retryAfterParser;
        $resolve = null;
        $requestBudgetMs = max(1, $timeoutMs);
        $connectBudgetMs = max(1, min($requestBudgetMs, (int) config('monitoring.connect_timeout_seconds') * 1000));

        if (filter_var($target['host'], FILTER_VALIDATE_IP) === false) {
            $ip = $target['ips'][0];
            $ipForCurl = str_contains($ip, ':') ? '['.$ip.']' : $ip;
            $resolve = [$target['host'].':'.$target['port'].':'.$ipForCurl];
        }

        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT_MS => $connectBudgetMs,
            CURLOPT_TIMEOUT_MS => $requestBudgetMs,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5'],
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CERTINFO => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($bodyBuffer): int {
                if (! $bodyBuffer->append($chunk)) {
                    return 0;
                }

                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$location, &$retryAfterHeader, $retryAfterParser): int {
                if (preg_match('/^Location:\s*(.*?)\s*$/i', $line, $matches) === 1) {
                    $location = trim($matches[1]);
                }

                $retryAfterValue = $retryAfterParser->headerValue($line);

                if ($retryAfterValue !== null) {
                    $retryAfterHeader = $retryAfterValue;
                }

                return strlen($line);
            },
        ];

        if ($resolve !== null) {
            $options[CURLOPT_RESOLVE] = $resolve;
        }

        if (! curl_setopt_array($handle, $options)) {
            curl_close($handle);

            return new HopResponse(null, null, '', 0, 'curl_policy_setup_failed', 'HTTP 用戶端無法套用所有必要的安全設定。', null);
        }

        $startedAt = hrtime(true);
        $completed = curl_exec($handle);
        $elapsedMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $primaryIp = (string) curl_getinfo($handle, CURLINFO_PRIMARY_IP);
        $errorNumber = curl_errno($handle);
        $tlsExpiresAt = $this->certificateExpiry(curl_getinfo($handle, CURLINFO_CERTINFO));
        $retryAfterSeconds = $this->retryAfterParser->seconds($retryAfterHeader);
        curl_close($handle);

        $pinnedIp = $target['ips'][0];

        if (($primaryIp !== '' && ! $this->sameIp($pinnedIp, $primaryIp)) || ($completed !== false && $primaryIp === '')) {
            return new HopResponse(null, null, '', $elapsedMs, 'pinned_ip_mismatch', '實際連線的 IP 與已驗證的位址不一致。', $tlsExpiresAt, $retryAfterSeconds);
        }

        if ($bodyBuffer->exceededLimit()) {
            return new HopResponse($statusCode ?: null, $location, $bodyBuffer->body(), $elapsedMs, 'body_too_large', '回應內容超過 1 MB 讀取上限。', $tlsExpiresAt, $retryAfterSeconds);
        }

        if ($completed === false || $errorNumber !== 0) {
            $errorType = match ($errorNumber) {
                CURLE_OPERATION_TIMEDOUT => 'timeout',
                CURLE_COULDNT_RESOLVE_HOST => 'dns_timeout',
                CURLE_SSL_CONNECT_ERROR => 'tls_handshake',
                CURLE_PEER_FAILED_VERIFICATION => 'tls_verify',
                default => 'connection_error',
            };
            $detail = match ($errorType) {
                'timeout' => '連線要求逾時。',
                'dns_timeout' => '無法連接至已驗證的 DNS 位址。',
                'tls_handshake' => 'TLS 交握失敗。',
                'tls_verify' => 'TLS 憑證驗證失敗。',
                default => '無法完成連線要求。',
            };

            return new HopResponse($statusCode ?: null, $location, '', $elapsedMs, $errorType, $detail, $tlsExpiresAt, $retryAfterSeconds);
        }

        return new HopResponse($statusCode ?: null, $location, $bodyBuffer->body(), $elapsedMs, null, null, $tlsExpiresAt, $retryAfterSeconds);
    }

    private function certificateExpiry(array|false $certificates): ?DateTimeImmutable
    {
        if (! is_array($certificates)) {
            return null;
        }

        foreach ($certificates as $certificate) {
            $value = $certificate['Expire date'] ?? null;

            if (! is_string($value) || $value === '') {
                continue;
            }

            try {
                return new DateTimeImmutable($value, new DateTimeZone('UTC'));
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function sameIp(string $expected, string $actual): bool
    {
        $expectedPacked = @inet_pton($expected);
        $actualPacked = @inet_pton($actual);

        return $expectedPacked !== false && $actualPacked !== false && hash_equals($expectedPacked, $actualPacked);
    }
}
