<?php

namespace App\Services;

use App\Models\CheckRun;
use App\Models\DnsChangeEvent;
use Illuminate\Database\Eloquent\Builder;

class DnsChangeRecorder
{
    public function recordFor(CheckRun $run): void
    {
        if ($run->source !== 'scheduled' || $run->status !== 'completed' || $run->finished_at === null) {
            return;
        }

        $currentIps = $this->normalizeIps($run->monitor_host_ips);
        $currentCnames = $this->normalizeCnames($run->monitor_host_cnames);
        $currentRecords = $this->normalizeRecords($run->monitor_host_dns_records);

        if ($run->monitor_host_ips === null || ($currentIps === [] && $run->error_type !== 'dns_nxdomain')) {
            return;
        }

        $previousRun = CheckRun::query()
            ->where('monitor_id', $run->monitor_id)
            ->where('config_version', $run->config_version)
            ->where('source', 'scheduled')
            ->where('status', 'completed')
            ->whereNotNull('finished_at')
            ->whereNotNull('monitor_host_ips')
            ->where(function (Builder $query): void {
                $query->whereJsonLength('monitor_host_ips', '>', 0)
                    ->orWhere('error_type', 'dns_nxdomain');
            })
            ->where('id', '<>', $run->id)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first(['id', 'monitor_host_ips', 'monitor_host_cnames', 'monitor_host_dns_records', 'error_type']);

        if ($previousRun === null || $previousRun->monitor_host_ips === null) {
            return;
        }

        $previousIps = $this->normalizeIps($previousRun->monitor_host_ips);
        $previousCnames = $this->normalizeCnames($previousRun->monitor_host_cnames);
        $ipAnswerChanged = $previousIps !== $currentIps;
        $cnameAnswerChanged = $previousRun->monitor_host_cnames !== null
            && $run->monitor_host_cnames !== null
            && $previousCnames !== $currentCnames;
        $dnsRecordsChanged = $previousRun->monitor_host_dns_records !== null
            && $run->monitor_host_dns_records !== null
            && $this->normalizeRecords($previousRun->monitor_host_dns_records) !== $currentRecords;

        if (! $ipAnswerChanged && ! $cnameAnswerChanged && ! $dnsRecordsChanged) {
            return;
        }

        DnsChangeEvent::query()->firstOrCreate(
            ['check_run_id' => $run->id],
            [
                'monitor_id' => $run->monitor_id,
                'previous_ips' => $previousIps,
                'current_ips' => $currentIps,
                'previous_cnames' => $previousRun->monitor_host_cnames === null ? null : $previousCnames,
                'current_cnames' => $run->monitor_host_cnames === null ? null : $currentCnames,
                'previous_records' => $previousRun->monitor_host_dns_records === null ? null : $this->normalizeRecords($previousRun->monitor_host_dns_records),
                'current_records' => $run->monitor_host_dns_records === null ? null : $currentRecords,
                'monitor_host_only' => true,
                'detected_at' => $run->finished_at,
            ],
        );
    }

    /** @param list<string>|null $ips
     * @return list<string>
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

    /**
     * @param  list<string>|null  $cnames
     * @return list<string>
     */
    private function normalizeCnames(?array $cnames): array
    {
        if ($cnames === null) {
            return [];
        }

        $normalizedCnames = array_values(array_unique(array_filter(array_map(
            static fn (mixed $cname): string => is_string($cname)
                ? mb_strtolower(rtrim(trim($cname), '.'))
                : '',
            $cnames,
        ), static fn (string $cname): bool => $cname !== '')));
        sort($normalizedCnames, SORT_STRING);

        return $normalizedCnames;
    }

    /**
     * @param  list<array{type: mixed, value: mixed}>|null  $records
     * @return list<array{type: 'CAA'|'MX'|'NS'|'TXT', value: string}>
     */
    private function normalizeRecords(?array $records): array
    {
        if ($records === null) {
            return [];
        }

        $normalizedRecords = [];

        foreach ($records as $record) {
            if (! is_array($record) || ! is_string($record['type'] ?? null) || ! is_string($record['value'] ?? null)) {
                continue;
            }

            $type = strtoupper(trim($record['type']));

            if (! in_array($type, ['CAA', 'MX', 'NS', 'TXT'], true)) {
                continue;
            }

            $normalizedRecords[] = ['type' => $type, 'value' => $record['value']];
        }

        $normalizedRecords = array_values(array_unique($normalizedRecords, SORT_REGULAR));
        usort($normalizedRecords, static fn (array $left, array $right): int => strcmp($left['type']."\0".$left['value'], $right['type']."\0".$right['value']));

        return $normalizedRecords;
    }
}
