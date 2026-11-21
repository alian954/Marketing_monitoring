<?php

namespace App\Services\Probe;

use DateTimeImmutable;

class FixtureProbeClient implements ProbeClient
{
    public function inspect(string $url, array $allowedRedirectHosts, string $userAgent, ?string $expectedText = null, ?int $timeoutSeconds = null, string $expectedTextMode = 'present', array $requestHeaders = [], ?string $expectedResponseHeader = null, ?string $socialPlatform = null): ProbeResult
    {
        $startedAt = hrtime(true);
        $parts = parse_url($url);
        $host = mb_strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($host, ['fixture.test', 'healthy.fixture.test'], true)) {
            return new ProbeResult(null, 0, '[離線測試網址無效]', [], [], 'fixture_host_rejected', '離線測試傳輸只接受 fixture.test 網域。', null, null);
        }

        $monitorHostIps = ['203.0.113.10'];
        $path = $parts['path'] ?? '/ok';
        $redirects = [];
        $finalUrl = $url;

        if ($path === '/redirect-https') {
            $redirects[] = 'https://fixture.test/ok';
            $finalUrl = 'https://fixture.test/ok';
            $path = '/ok';
        }

        if ($path === '/redirect-private') {
            $redirects[] = 'http://127.0.0.1/admin';

            return new ProbeResult(null, 2, 'http://fixture.test/redirect-private', $redirects, [], 'blocked_ssrf', '離線測試模式在未連線的情況下封鎖了私有位址重新導向。', null, null, $monitorHostIps);
        }

        if ($path === '/redirect-loop') {
            $redirects[] = $url;

            return new ProbeResult(null, 2, $url, $redirects, [], 'redirect_loop', '偵測到重新導向迴圈。', null, null, $monitorHostIps);
        }

        if ($path === '/timeout') {
            return new ProbeResult(null, 10_000, $url, [], [], 'timeout', '離線測試模式模擬連線逾時。', null, null, $monitorHostIps);
        }

        $statusCode = match ($path) {
            '/403', '/forbidden' => 403,
            '/404', '/not-found' => 404,
            '/500', '/server-error' => 500,
            '/429' => 429,
            '/adsbot-difference' => str_contains($userAgent, 'AdsBot') ? 403 : 200,
            default => 200,
        };
        $body = match ($path) {
            '/missing-text' => '<html>fixture page without the expected copy</html>',
            '/html-entities' => '<html>Campaign &amp; special offer</html>',
            default => '<html>fixture healthy landing page</html>',
        };
        $elapsedMs = (int) round((hrtime(true) - $startedAt) / 1_000_000) + 2;
        $matchesExpectedText = null;
        $expectedTextTermMatches = null;

        if ($expectedText !== null && $expectedText !== '') {
            $matchDetails = ExpectedTextMatcher::evaluate($body, $expectedText, $expectedTextMode);
            $matchesExpectedText = $matchDetails['found'];
            $expectedTextTermMatches = array_column($matchDetails['terms'], 'matched');
        }
        $responseHeaders = ['content-type' => ['text/html; charset=UTF-8']];

        if ($path !== '/missing-response-header') {
            $responseHeaders['x-robots-tag'] = ['index, follow'];
        }

        $matchesExpectedResponseHeader = ResponseHeaderCondition::matches($responseHeaders, $expectedResponseHeader);
        $expiry = str_starts_with($finalUrl, 'https://') ? (new DateTimeImmutable('+30 days')) : null;
        $responseInspection = ResponseMetadataExtractor::inspect($body, $finalUrl, $responseHeaders);

        return new ProbeResult(
            $statusCode,
            $elapsedMs,
            $finalUrl,
            $redirects,
            $monitorHostIps,
            null,
            null,
            $matchesExpectedText,
            $expiry,
            $monitorHostIps,
            null,
            $matchesExpectedResponseHeader,
            $expectedTextTermMatches,
            responseVisibleTextHash: $responseInspection['visible_text_hash'],
            responseContentType: 'text/html; charset=UTF-8',
            responseBodyBytes: strlen($body),
            responseRobotsDirectives: $responseInspection['robots_directives'],
        );
    }
}
