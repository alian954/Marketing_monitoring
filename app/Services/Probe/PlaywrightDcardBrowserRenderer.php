<?php

namespace App\Services\Probe;

use Symfony\Component\Process\Process;
use Throwable;

class PlaywrightDcardBrowserRenderer implements DcardBrowserRenderer
{
    /** @param array{url: string, host: string, port: int, ips: list<string>, cnames: list<string>|null, dns_records: list<array{type: 'MX'|'NS'|'TXT'|'CAA', value: string}>|null} $target */
    public function render(array $target, int $timeoutMilliseconds): ?HopResponse
    {
        $publicIpv4 = collect($target['ips'])
            ->first(static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false);

        if (! is_string($publicIpv4)
            || ! $this->isDcardHost($target['host'])
            || parse_url($target['url'], PHP_URL_SCHEME) !== 'https'
            || $target['port'] !== 443) {
            return null;
        }

        $timeoutMilliseconds = max(1, min(15_000, $timeoutMilliseconds));
        $timeoutSeconds = max(1, (int) ceil($timeoutMilliseconds / 1_000));
        $process = new Process([
            (string) config('monitoring.dcard_browser_node_binary', 'node'),
            resource_path('js/dcard-public-page-renderer.mjs'),
            $target['url'],
            $target['host'],
            $publicIpv4,
            (string) $timeoutMilliseconds,
            (string) config('monitoring.dcard_browser_channel', 'chromium'),
        ], base_path());
        $process->setTimeout($timeoutSeconds);

        try {
            $process->run();
        } catch (Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        try {
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($result)
            || ! is_int($result['status'] ?? null)
            || ! is_string($result['html'] ?? null)
            || ! is_string($result['visible_text'] ?? null)
            || ! is_string($result['final_url'] ?? null)
            || ! $this->hasSafeFinalUrl($target['url'], $result['final_url'], $target['host'])) {
            return null;
        }

        $contentType = is_string($result['content_type'] ?? null)
            ? $result['content_type']
            : 'text/html; charset=utf-8';

        return new HopResponse(
            $result['status'],
            null,
            $result['html'],
            max(0, (int) ($result['elapsed_ms'] ?? 0)),
            null,
            null,
            null,
            responseHeaders: ['content-type' => [$contentType]],
            visibleText: $result['visible_text'],
        );
    }

    private function isDcardHost(string $host): bool
    {
        return $host === 'dcard.tw' || str_ends_with($host, '.dcard.tw');
    }

    private function hasSafeFinalUrl(string $initialUrl, string $finalUrl, string $host): bool
    {
        $initial = parse_url($initialUrl);
        $final = parse_url($finalUrl);

        return is_array($initial)
            && is_array($final)
            && ($initial['scheme'] ?? null) === 'https'
            && ($final['scheme'] ?? null) === 'https'
            && ($final['host'] ?? null) === $host
            && ! isset($final['user'])
            && ! isset($final['pass'])
            && (! isset($final['port']) || (int) $final['port'] === 443);
    }
}
