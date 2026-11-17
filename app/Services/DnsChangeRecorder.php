<?php

namespace App\Services;

use App\Models\CheckRun;
use App\Models\DnsChangeEvent;

class DnsChangeRecorder
{
    public function recordFor(CheckRun $run): void
    {
        if ($run->source !== 'scheduled' || $run->status !== 'completed' || $run->finished_at === null) {
            return;
        }

        $currentIps = $this->normalizeIps($run->monitor_host_ips);

        if ($currentIps === []) {
            return;
        }

        $previousRun = CheckRun::query()
            ->where('monitor_id', $run->monitor_id)
            ->where('config_version', $run->config_version)
            ->where('source', 'scheduled')
            ->where('status', 'completed')
            ->whereNotNull('finished_at')
            ->where('id', '<>', $run->id)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first(['id', 'monitor_host_ips']);

        if ($previousRun === null || $previousRun->monitor_host_ips === null) {
            return;
        }

        $previousIps = $this->normalizeIps($previousRun->monitor_host_ips);

        if ($previousIps === [] || $previousIps === $currentIps) {
            return;
        }

        DnsChangeEvent::query()->firstOrCreate(
            ['check_run_id' => $run->id],
            [
                'monitor_id' => $run->monitor_id,
                'previous_ips' => $previousIps,
                'current_ips' => $currentIps,
                'monitor_host_only' => true,
                'detected_at' => $run->finished_at,
            ],
        );
    }

    /** @param list<string>|null $ips
     *  @return list<string>
     */
    private function normalizeIps(?array $ips): array
    {
        if ($ips === null) {
            return [];
        }

        $normalizedIps = array_values(array_unique(array_filter(
            $ips,
            static fn (mixed $ip): bool => is_string($ip) && $ip !== '',
        )));
        sort($normalizedIps, SORT_STRING);

        return $normalizedIps;
    }
}
