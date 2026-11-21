<?php

namespace App\Services\Probe;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Throwable;

class SecureRobotsTxtProbe implements RobotsTxtProbe
{
    private const USER_AGENT = 'MarketingMonitorRobotsCheck/1.0 (+authorized website crawler review)';

    public function __construct(
        private readonly UrlSafetyPolicy $policy,
        private readonly ProbeTransport $transport,
        private readonly RobotsTxtParser $parser,
    ) {}

    public function inspect(string $url, array $allowedRedirectHosts = []): RobotsTxtResult
    {
        $robotsUrl = $this->robotsUrl($url);

        if ($robotsUrl === null) {
            return RobotsTxtResult::failed(null, 'invalid_url');
        }

        $targetPath = $this->targetPath($url);
        $currentUrl = $robotsUrl;
        $originHost = null;
        $visited = [];
        $requestTimeoutSeconds = max(1, min(15, (int) config('monitoring.request_timeout_seconds')));
        $deadline = hrtime(true) + $requestTimeoutSeconds * 1_000_000_000;
        $maximumRedirects = max(0, min(10, (int) config('monitoring.max_redirects')));

        for ($hop = 0; $hop <= $maximumRedirects; $hop++) {
            $remainingMilliseconds = $this->remainingMilliseconds($deadline);

            if ($remainingMilliseconds === 0) {
                return RobotsTxtResult::failed(null, 'timeout');
            }

            try {
                $target = $this->policy->validateAndResolve($currentUrl, $allowedRedirectHosts, $originHost);
            } catch (ProbeException $exception) {
                return RobotsTxtResult::failed(null, $exception->type);
            }

            $originHost ??= $target['host'];

            if (isset($visited[$target['url']])) {
                return RobotsTxtResult::failed(null, 'redirect_loop');
            }

            $visited[$target['url']] = true;
            $remainingMilliseconds = $this->remainingMilliseconds($deadline);

            if ($remainingMilliseconds === 0) {
                return RobotsTxtResult::failed(null, 'timeout');
            }

            $response = $this->transport->get($target, self::USER_AGENT, $remainingMilliseconds);

            if ($response->errorType !== null) {
                return RobotsTxtResult::failed($response->statusCode, $response->errorType);
            }

            if (in_array($response->statusCode, [301, 302, 303, 307, 308], true)) {
                if (! is_string($response->location) || trim($response->location) === '') {
                    return RobotsTxtResult::failed($response->statusCode, 'redirect_invalid');
                }

                if ($hop === $maximumRedirects) {
                    return RobotsTxtResult::failed($response->statusCode, 'redirect_limit');
                }

                try {
                    $currentUrl = (string) UriResolver::resolve(new Uri($target['url']), new Uri($response->location));
                } catch (Throwable) {
                    return RobotsTxtResult::failed($response->statusCode, 'redirect_invalid');
                }

                continue;
            }

            return $this->resultForResponse($response->statusCode, $response->body, $targetPath);
        }

        return RobotsTxtResult::failed(null, 'redirect_limit');
    }

    private function robotsUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        $scheme = mb_strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        try {
            $host = $this->policy->normalizeHost((string) $parts['host']);
        } catch (ProbeException) {
            return null;
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port = isset($parts['port']) ? (int) $parts['port'] : $defaultPort;

        if ($port !== $defaultPort) {
            return null;
        }

        $formattedHost = str_contains($host, ':') ? '['.$host.']' : $host;
        $portSuffix = isset($parts['port']) ? ':'.$port : '';

        return $scheme.'://'.$formattedHost.$portSuffix.'/robots.txt';
    }

    private function targetPath(string $url): string
    {
        $parts = parse_url($url);
        $path = is_array($parts) && is_string($parts['path'] ?? null) && $parts['path'] !== '' ? $parts['path'] : '/';

        if (is_array($parts) && is_string($parts['query'] ?? null) && $parts['query'] !== '') {
            $path .= '?'.$parts['query'];
        }

        return $path;
    }

    private function remainingMilliseconds(int $deadline): int
    {
        $remainingNanoseconds = $deadline - hrtime(true);

        return $remainingNanoseconds <= 0 ? 0 : (int) floor($remainingNanoseconds / 1_000_000);
    }

    private function resultForResponse(?int $statusCode, string $body, string $targetPath): RobotsTxtResult
    {
        if ($statusCode === null) {
            return RobotsTxtResult::failed(null, 'connection_error');
        }

        if (in_array($statusCode, [404, 410], true)) {
            return $this->parsedResult('missing', $statusCode, '', $targetPath);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $errorType = $statusCode >= 500 ? 'http_server_error' : 'http_client_error';

            return new RobotsTxtResult('unavailable', $statusCode, $errorType, null, null, null, null);
        }

        return $this->parsedResult('available', $statusCode, $body, $targetPath);
    }

    private function parsedResult(string $fileState, int $statusCode, string $body, string $targetPath): RobotsTxtResult
    {
        $analysis = $this->parser->analyze($body, $targetPath);

        return new RobotsTxtResult(
            $fileState,
            $statusCode,
            null,
            $analysis['policy_hash'],
            $analysis['crawler_decisions'],
            $analysis['rule_count'],
            $analysis['sitemap_count'],
        );
    }
}
