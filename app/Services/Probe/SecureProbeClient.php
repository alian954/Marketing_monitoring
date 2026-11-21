<?php

namespace App\Services\Probe;

use App\Services\UrlMasker;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;
use Throwable;

class SecureProbeClient implements ProbeClient
{
    public function __construct(
        private readonly UrlSafetyPolicy $policy,
        private readonly ProbeTransport $transport,
        private readonly UrlMasker $masker,
    ) {}

    public function inspect(string $url, array $allowedRedirectHosts, string $userAgent, ?string $expectedText = null, ?int $timeoutSeconds = null, string $expectedTextMode = 'present', array $requestHeaders = [], ?string $expectedResponseHeader = null, ?string $socialPlatform = null): ProbeResult
    {
        $startedAt = hrtime(true);

        try {
            $requestHeaders = RequestHeaderParser::normalize($requestHeaders);
        } catch (InvalidArgumentException) {
            return $this->failure(new ProbeException('invalid_request_header', '自訂 HTTP 標頭設定無效。'), $startedAt, [], [], $url);
        }

        try {
            $expectedResponseHeader = ResponseHeaderCondition::normalizedCondition($expectedResponseHeader);
        } catch (InvalidArgumentException) {
            return $this->failure(new ProbeException('invalid_response_header_condition', '回應標頭檢查條件無效。'), $startedAt, [], [], $url);
        }

        if ($requestHeaders !== [] && mb_strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return $this->failure(new ProbeException('request_header_requires_https', '自訂 HTTP 標頭只會透過 HTTPS 傳送。'), $startedAt, [], [], $url);
        }

        $requestTimeoutSeconds = max(1, min(15, $timeoutSeconds ?? (int) config('monitoring.request_timeout_seconds')));
        $currentUrl = $url;
        $originHost = null;
        $visited = [];
        $redirects = [];
        $resolvedIps = [];
        $monitorHostIps = [];
        $monitorHostCnames = null;
        $monitorHostDnsRecords = null;
        $elapsedMs = 0;
        $maxRedirects = (int) config('monitoring.max_redirects');
        $expectedResponseHeaderName = ResponseHeaderCondition::headerName($expectedResponseHeader);
        $deadline = $startedAt + $requestTimeoutSeconds * 1_000_000_000;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $remainingMs = $this->remainingMilliseconds($deadline);

            if ($remainingMs === 0) {
                return $this->failure(
                    $this->timeoutException($requestTimeoutSeconds),
                    $startedAt,
                    $redirects,
                    $resolvedIps,
                    $currentUrl,
                    $elapsedMs,
                    monitorHostIps: $monitorHostIps,
                    monitorHostCnames: $monitorHostCnames,
                    monitorHostDnsRecords: $monitorHostDnsRecords,
                );
            }

            try {
                $target = $this->policy->validateAndResolve($currentUrl, $allowedRedirectHosts, $originHost);
                $originHost ??= $target['host'];

                if ($hop === 0) {
                    $monitorHostIps = $target['ips'];
                    $monitorHostCnames = $target['cnames'];
                    $monitorHostDnsRecords = $target['dns_records'];
                }
            } catch (ProbeException $exception) {
                return $this->failure($exception, $startedAt, $redirects, $resolvedIps, $currentUrl, monitorHostIps: $monitorHostIps, monitorHostCnames: $monitorHostCnames, monitorHostDnsRecords: $monitorHostDnsRecords);
            }

            $remainingMs = $this->remainingMilliseconds($deadline);

            if ($remainingMs === 0) {
                return $this->failure($this->timeoutException($requestTimeoutSeconds), $startedAt, $redirects, $resolvedIps, $target['url'], $elapsedMs, monitorHostIps: $monitorHostIps, monitorHostCnames: $monitorHostCnames, monitorHostDnsRecords: $monitorHostDnsRecords);
            }

            if (isset($visited[$target['url']])) {
                return $this->failure(new ProbeException('redirect_loop', '偵測到重新導向迴圈。'), $startedAt, $redirects, $resolvedIps, $target['url'], monitorHostIps: $monitorHostIps, monitorHostCnames: $monitorHostCnames, monitorHostDnsRecords: $monitorHostDnsRecords);
            }

            $visited[$target['url']] = true;
            $resolvedIps = array_values(array_unique([...$resolvedIps, ...$target['ips']]));

            $response = null;

            foreach ($target['ips'] as $index => $ip) {
                $remainingMs = $this->remainingMilliseconds($deadline);

                if ($remainingMs === 0) {
                    return $this->failure($this->timeoutException($requestTimeoutSeconds), $startedAt, $redirects, $resolvedIps, $target['url'], $elapsedMs, $response?->tlsExpiresAt, $monitorHostIps, $monitorHostCnames, $monitorHostDnsRecords);
                }

                $attemptTarget = $target;
                unset($attemptTarget['cnames'], $attemptTarget['dns_records']);
                $attemptTarget['ips'] = [$ip];
                $headersForHop = $this->sameOrigin($url, $target['url']) ? $requestHeaders : [];
                $response = $this->transport instanceof HeaderAwareProbeTransport && ($headersForHop !== [] || $expectedResponseHeaderName !== null)
                    ? $this->transport->getWithHeaders($attemptTarget, $userAgent, $remainingMs, $headersForHop, $expectedResponseHeaderName)
                    : $this->transport->get($attemptTarget, $userAgent, $remainingMs);
                $elapsedMs += $response->elapsedMs;

                if (hrtime(true) >= $deadline) {
                    return $this->failure($this->timeoutException($requestTimeoutSeconds), $startedAt, $redirects, $resolvedIps, $target['url'], $elapsedMs, $response->tlsExpiresAt, $monitorHostIps, $monitorHostCnames, $monitorHostDnsRecords);
                }

                $hasAnotherIp = $index < count($target['ips']) - 1;
                $canTryAnotherIp = $response->statusCode === null
                    && in_array($response->errorType, ['connection_error', 'timeout', 'dns_timeout', 'tls_handshake', 'tls_verify'], true);

                if (! $hasAnotherIp || ! $canTryAnotherIp) {
                    break;
                }
            }

            $response ??= new HopResponse(null, null, '', 0, 'connection_error', '無法完成連線要求。', null);

            $isRedirectResponse = in_array($response->statusCode, [301, 302, 303, 307, 308], true);
            $oversizedRedirectCanContinue = $response->errorType === 'body_too_large'
                && $isRedirectResponse
                && $response->location !== null
                && trim($response->location) !== '';
            $responseInspection = ! $isRedirectResponse
                ? ResponseMetadataExtractor::inspect(
                    $response->body,
                    $target['url'],
                    $response->responseHeaders,
                    $response->errorType !== 'body_too_large',
                )
                : null;
            $responseMetadata = $responseInspection['metadata'] ?? ['title' => null, 'description' => null, 'image_reference_hash' => null, 'open_graph_type' => null, 'canonical_reference_hash' => null, 'published_at' => null, 'modified_at' => null];
            $responseRobotsDirectives = $responseInspection['robots_directives'] ?? null;
            $responseVisibleTextHash = $response->errorType === null
                ? ($responseInspection['visible_text_hash'] ?? null)
                : null;
            $responseContentType = $this->responseContentType($response->responseHeaders);
            $responseBodyBytes = $response->errorType === null ? strlen($response->body) : null;
            $expectedResponseHeaderFound = in_array($response->errorType, [null, 'body_too_large'], true)
                ? ResponseHeaderCondition::matches($response->responseHeaders, $expectedResponseHeader)
                : null;

            $boundedPrefixMatch = null;
            $expectedTextTermMatches = null;

            if ($response->errorType === 'body_too_large' && $expectedText !== null && $expectedText !== '') {
                $matchDetails = ExpectedTextMatcher::evaluate($response->body, $expectedText, $expectedTextMode, true);
                $boundedPrefixMatch = $matchDetails['found'];
                $expectedTextTermMatches = array_column($matchDetails['terms'], 'matched');
            }

            if ($response->errorType === 'body_too_large' && ! $isRedirectResponse && $boundedPrefixMatch === true) {
                return new ProbeResult(
                    $response->statusCode,
                    $elapsedMs,
                    $this->masker->mask($target['url']),
                    $redirects,
                    $resolvedIps,
                    null,
                    null,
                    true,
                    $response->tlsExpiresAt,
                    $monitorHostIps,
                    $response->retryAfterSeconds,
                    $expectedResponseHeaderFound,
                    $expectedTextTermMatches,
                    $responseMetadata['title'],
                    $responseMetadata['description'],
                    $responseMetadata['image_reference_hash'],
                    $responseMetadata['open_graph_type'],
                    $responseMetadata['canonical_reference_hash'],
                    $responseMetadata['published_at'],
                    $responseMetadata['modified_at'],
                    responseContentType: $responseContentType,
                    monitorHostCnames: $monitorHostCnames,
                    monitorHostDnsRecords: $monitorHostDnsRecords,
                    responseRobotsDirectives: $responseRobotsDirectives,
                );
            }

            if (
                $response->errorType === 'body_too_large'
                && ! $isRedirectResponse
                && ($expectedText === null || $expectedText === '')
            ) {
                return new ProbeResult(
                    $response->statusCode,
                    $elapsedMs,
                    $this->masker->mask($target['url']),
                    $redirects,
                    $resolvedIps,
                    null,
                    null,
                    null,
                    $response->tlsExpiresAt,
                    $monitorHostIps,
                    $response->retryAfterSeconds,
                    $expectedResponseHeaderFound,
                    null,
                    $responseMetadata['title'],
                    $responseMetadata['description'],
                    $responseMetadata['image_reference_hash'],
                    $responseMetadata['open_graph_type'],
                    $responseMetadata['canonical_reference_hash'],
                    $responseMetadata['published_at'],
                    $responseMetadata['modified_at'],
                    responseContentType: $responseContentType,
                    monitorHostCnames: $monitorHostCnames,
                    monitorHostDnsRecords: $monitorHostDnsRecords,
                    responseRobotsDirectives: $responseRobotsDirectives,
                );
            }

            if ($response->errorType !== null && ! $oversizedRedirectCanContinue) {
                return new ProbeResult(
                    $response->statusCode,
                    $elapsedMs,
                    $this->masker->mask($target['url']),
                    $redirects,
                    $resolvedIps,
                    $response->errorType,
                    $response->errorDetail,
                    null,
                    $response->tlsExpiresAt,
                    $monitorHostIps,
                    $response->retryAfterSeconds,
                    null,
                    $expectedTextTermMatches,
                    $responseMetadata['title'],
                    $responseMetadata['description'],
                    $responseMetadata['image_reference_hash'],
                    $responseMetadata['open_graph_type'],
                    $responseMetadata['canonical_reference_hash'],
                    $responseMetadata['published_at'],
                    $responseMetadata['modified_at'],
                    responseContentType: $responseContentType,
                    monitorHostCnames: $monitorHostCnames,
                    monitorHostDnsRecords: $monitorHostDnsRecords,
                    responseRobotsDirectives: $responseRobotsDirectives,
                );
            }

            if (! $isRedirectResponse) {
                $matchDetails = $expectedText === null || $expectedText === ''
                    ? null
                    : ExpectedTextMatcher::evaluate($response->body, $expectedText, $expectedTextMode);
                $matchesExpectedText = $matchDetails['found'] ?? null;
                $expectedTextTermMatches = $matchDetails === null
                    ? null
                    : array_column($matchDetails['terms'], 'matched');
                $accessGateDetected = $socialPlatform !== null
                    && $response->statusCode !== null
                    && $response->statusCode >= 200
                    && $response->statusCode < 400
                    && $expectedText !== null
                    && $expectedText !== ''
                    && $matchesExpectedText === false
                    && $expectedTextMode !== ExpectedTextMatcher::MODE_ABSENT
                    && $expectedResponseHeaderFound !== false
                    && SocialPostAccessGateDetector::detects($socialPlatform, $response->body, $responseMetadata);

                if ($accessGateDetected) {
                    $matchesExpectedText = null;
                    $expectedTextTermMatches = array_fill(0, count(ExpectedTextMatcher::terms($expectedText)), null);
                }

                return new ProbeResult(
                    $response->statusCode,
                    $elapsedMs,
                    $this->masker->mask($target['url']),
                    $redirects,
                    $resolvedIps,
                    $accessGateDetected ? 'social_access_required' : null,
                    $accessGateDetected ? '平台公開回應包含登入、驗證或暫時限制提示，這次無法判定預期貼文文字；請手動開啟網址核對。' : null,
                    $matchesExpectedText,
                    $response->tlsExpiresAt,
                    $monitorHostIps,
                    $response->retryAfterSeconds,
                    $expectedResponseHeaderFound,
                    $expectedTextTermMatches,
                    $responseMetadata['title'],
                    $responseMetadata['description'],
                    $responseMetadata['image_reference_hash'],
                    $responseMetadata['open_graph_type'],
                    $responseMetadata['canonical_reference_hash'],
                    $responseMetadata['published_at'],
                    $responseMetadata['modified_at'],
                    $responseVisibleTextHash,
                    $responseContentType,
                    $responseBodyBytes,
                    $monitorHostCnames,
                    $monitorHostDnsRecords,
                    $responseRobotsDirectives,
                );
            }

            if ($response->location === null || trim($response->location) === '') {
                return $this->failure(new ProbeException('redirect_invalid', '重新導向回應缺少 Location 標頭。'), $startedAt, $redirects, $resolvedIps, $target['url'], $elapsedMs, $response->tlsExpiresAt, $monitorHostIps, $monitorHostCnames, $monitorHostDnsRecords);
            }

            try {
                $nextUrl = (string) UriResolver::resolve(new Uri($target['url']), new Uri($response->location));
            } catch (Throwable) {
                return $this->failure(new ProbeException('redirect_invalid', '重新導向目標網址無效。'), $startedAt, $redirects, $resolvedIps, $target['url'], $elapsedMs, $response->tlsExpiresAt, $monitorHostIps, $monitorHostCnames, $monitorHostDnsRecords);
            }

            $redirects[] = $this->masker->mask($nextUrl);

            if ($hop === $maxRedirects) {
                return $this->failure(new ProbeException('redirect_limit', '已超過重新導向次數上限。'), $startedAt, $redirects, $resolvedIps, $target['url'], $elapsedMs, $response->tlsExpiresAt, $monitorHostIps, $monitorHostCnames, $monitorHostDnsRecords);
            }

            $currentUrl = $nextUrl;
        }

        return $this->failure(new ProbeException('redirect_limit', '已超過重新導向次數上限。'), $startedAt, $redirects, $resolvedIps, $currentUrl, $elapsedMs, monitorHostIps: $monitorHostIps, monitorHostCnames: $monitorHostCnames, monitorHostDnsRecords: $monitorHostDnsRecords);
    }

    private function remainingMilliseconds(int $deadline): int
    {
        $remainingNanoseconds = $deadline - hrtime(true);

        return $remainingNanoseconds <= 0 ? 0 : (int) floor($remainingNanoseconds / 1_000_000);
    }

    private function sameOrigin(string $leftUrl, string $rightUrl): bool
    {
        $left = parse_url($leftUrl);
        $right = parse_url($rightUrl);

        if (! is_array($left) || ! is_array($right)) {
            return false;
        }

        $leftScheme = mb_strtolower((string) ($left['scheme'] ?? ''));
        $rightScheme = mb_strtolower((string) ($right['scheme'] ?? ''));
        $leftPort = (int) ($left['port'] ?? ($leftScheme === 'https' ? 443 : 80));
        $rightPort = (int) ($right['port'] ?? ($rightScheme === 'https' ? 443 : 80));

        return $leftScheme === $rightScheme
            && mb_strtolower((string) ($left['host'] ?? '')) === mb_strtolower((string) ($right['host'] ?? ''))
            && $leftPort === $rightPort;
    }

    private function timeoutException(int $timeoutSeconds): ProbeException
    {
        return new ProbeException('timeout', '整體檢查已超過 '.$timeoutSeconds.' 秒時間上限。');
    }

    /** @param array<string, list<string>> $responseHeaders */
    private function responseContentType(array $responseHeaders): ?string
    {
        $contentType = $responseHeaders['content-type'][0] ?? null;

        if (! is_string($contentType)) {
            return null;
        }

        $contentType = preg_replace('/[\x00-\x1F\x7F]/', '', trim($contentType));

        return $contentType === null || $contentType === '' ? null : mb_substr($contentType, 0, 255);
    }

    /** @param list<string> $redirects @param list<string> $resolvedIps */
    private function failure(
        ProbeException $exception,
        int $startedAt,
        array $redirects,
        array $resolvedIps,
        string $url,
        ?int $elapsedMs = null,
        ?\DateTimeImmutable $tlsExpiresAt = null,
        array $monitorHostIps = [],
        ?array $monitorHostCnames = null,
        ?array $monitorHostDnsRecords = null,
    ): ProbeResult {
        return new ProbeResult(
            null,
            $elapsedMs ?? (int) round((hrtime(true) - $startedAt) / 1_000_000),
            $this->masker->mask($url),
            $redirects,
            $resolvedIps,
            $exception->type,
            $exception->getMessage(),
            null,
            $tlsExpiresAt,
            $monitorHostIps,
            monitorHostCnames: $monitorHostCnames,
            monitorHostDnsRecords: $monitorHostDnsRecords,
        );
    }
}
