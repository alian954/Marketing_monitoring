<?php

namespace App\Services\Probe;

use App\Services\UrlMasker;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Throwable;

class SecureProbeClient implements ProbeClient
{
    public function __construct(
        private readonly UrlSafetyPolicy $policy,
        private readonly ProbeTransport $transport,
        private readonly UrlMasker $masker,
    ) {}

    public function inspect(string $url, array $allowedRedirectHosts, string $userAgent, ?string $expectedText = null): ProbeResult
    {
        $startedAt = hrtime(true);
        $currentUrl = $url;
        $originHost = null;
        $visited = [];
        $redirects = [];
        $resolvedIps = [];
        $elapsedMs = 0;
        $maxRedirects = (int) config('monitoring.max_redirects');
        $deadline = $startedAt + max(1, (int) config('monitoring.request_timeout_seconds')) * 1_000_000_000;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $remainingMs = $this->remainingMilliseconds($deadline);

            if ($remainingMs === 0) {
                return $this->failure($this->timeoutException(), $startedAt, $redirects, $resolvedIps, $currentUrl, $elapsedMs);
            }

            try {
                $target = $this->policy->validateAndResolve($currentUrl, $allowedRedirectHosts, $originHost);
                $originHost ??= $target['host'];
            } catch (ProbeException $exception) {
                return $this->failure($exception, $startedAt, $redirects, $resolvedIps, $currentUrl);
            }

            $remainingMs = $this->remainingMilliseconds($deadline);

            if ($remainingMs === 0) {
                return $this->failure($this->timeoutException(), $startedAt, $redirects, $resolvedIps, $target['url'], $elapsedMs);
            }

            if (isset($visited[$target['url']])) {
                return $this->failure(new ProbeException('redirect_loop', '偵測到重新導向迴圈。'), $startedAt, $redirects, $resolvedIps, $target['url']);
            }

            $visited[$target['url']] = true;
            $resolvedIps = array_values(array_unique([...$resolvedIps, ...$target['ips']]));
            $response = $this->transport->get($target, $userAgent, $remainingMs);
            $elapsedMs += $response->elapsedMs;

            if (hrtime(true) >= $deadline) {
                return $this->failure($this->timeoutException(), $startedAt, $redirects, $resolvedIps, $target['url'], $elapsedMs, $response->tlsExpiresAt);
            }

            if ($response->errorType !== null) {
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
                );
            }

            if (! in_array($response->statusCode, [301, 302, 303, 307, 308], true)) {
                $matchesExpectedText = $expectedText === null || $expectedText === ''
                    ? null
                    : mb_stripos($response->body, $expectedText) !== false;

                return new ProbeResult(
                    $response->statusCode,
                    $elapsedMs,
                    $this->masker->mask($target['url']),
                    $redirects,
                    $resolvedIps,
                    null,
                    null,
                    $matchesExpectedText,
                    $response->tlsExpiresAt,
                );
            }

            if ($response->location === null || trim($response->location) === '') {
                return $this->failure(new ProbeException('redirect_invalid', '重新導向回應缺少 Location 標頭。'), $startedAt, $redirects, $resolvedIps, $target['url'], $elapsedMs, $response->tlsExpiresAt);
            }

            try {
                $nextUrl = (string) UriResolver::resolve(new Uri($target['url']), new Uri($response->location));
            } catch (Throwable) {
                return $this->failure(new ProbeException('redirect_invalid', '重新導向目標網址無效。'), $startedAt, $redirects, $resolvedIps, $target['url'], $elapsedMs, $response->tlsExpiresAt);
            }

            $redirects[] = $this->masker->mask($nextUrl);

            if ($hop === $maxRedirects) {
                return $this->failure(new ProbeException('redirect_limit', '已超過重新導向次數上限。'), $startedAt, $redirects, $resolvedIps, $target['url'], $elapsedMs, $response->tlsExpiresAt);
            }

            $currentUrl = $nextUrl;
        }

        return $this->failure(new ProbeException('redirect_limit', '已超過重新導向次數上限。'), $startedAt, $redirects, $resolvedIps, $currentUrl, $elapsedMs);
    }

    private function remainingMilliseconds(int $deadline): int
    {
        $remainingNanoseconds = $deadline - hrtime(true);

        return $remainingNanoseconds <= 0 ? 0 : (int) floor($remainingNanoseconds / 1_000_000);
    }

    private function timeoutException(): ProbeException
    {
        return new ProbeException('timeout', '整體檢查已超過 '.(int) config('monitoring.request_timeout_seconds').' 秒時間上限。');
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
        );
    }
}
