<?php

namespace App\Services;

use App\Models\CheckRun;
use App\Models\Monitor;
use Carbon\CarbonImmutable;

class RedirectChangeHistory
{
    private const MAX_CHANGES = 10;

    /**
     * @return list<array{
     *     changed_at: CarbonImmutable,
     *     previous_redirects: list<string>,
     *     current_redirects: list<string>
     * }>
     */
    public function build(Monitor $monitor): array
    {
        $changes = [];
        $newerRun = null;

        foreach ($monitor->checkRuns()
            ->where('status', 'completed')
            ->whereNotIn('source', ['tls_daily', 'domain_daily'])
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->select(['id', 'config_version', 'scheduled_at', 'redirects'])
            ->cursor() as $run) {
            if ($newerRun instanceof CheckRun && $newerRun->config_version === $run->config_version) {
                $previousRedirects = $this->normalizeRedirects($run->redirects);
                $currentRedirects = $this->normalizeRedirects($newerRun->redirects);

                if ($previousRedirects !== $currentRedirects) {
                    $changes[] = [
                        'changed_at' => $newerRun->scheduled_at,
                        'previous_redirects' => $previousRedirects,
                        'current_redirects' => $currentRedirects,
                    ];

                    if (count($changes) >= self::MAX_CHANGES) {
                        break;
                    }
                }
            }

            $newerRun = $run;
        }

        return $changes;
    }

    /** @param array<mixed>|null $redirects
     * @return list<string>
     */
    private function normalizeRedirects(?array $redirects): array
    {
        $normalized = [];

        foreach ($redirects ?? [] as $redirect) {
            $url = is_string($redirect)
                ? $redirect
                : (is_array($redirect) && is_string($redirect['url'] ?? null) ? $redirect['url'] : null);

            if ($url === null) {
                continue;
            }

            $destination = $this->normalizeDestination($url);

            if ($destination !== null) {
                $normalized[] = $destination;
            }
        }

        return $normalized;
    }

    private function normalizeDestination(string $url): ?string
    {
        try {
            $parts = parse_url(trim($url));
        } catch (\ValueError) {
            return null;
        }

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $portIsDefault = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        $authority = $host.($port !== null && ! $portIsDefault ? ':'.$port : '');

        return $scheme.'://'.$authority.($parts['path'] ?? '/');
    }
}
