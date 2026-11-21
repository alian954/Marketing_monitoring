<?php

namespace App\Models;

use Database\Factories\DnsChangeEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['monitor_id', 'check_run_id', 'previous_ips', 'current_ips', 'previous_cnames', 'current_cnames', 'previous_records', 'current_records', 'monitor_host_only', 'detected_at'])]
class DnsChangeEvent extends Model
{
    /** @use HasFactory<DnsChangeEventFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'previous_ips' => 'array',
            'current_ips' => 'array',
            'previous_cnames' => 'array',
            'current_cnames' => 'array',
            'previous_records' => 'array',
            'current_records' => 'array',
            'monitor_host_only' => 'boolean',
            'detected_at' => 'immutable_datetime',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function checkRun(): BelongsTo
    {
        return $this->belongsTo(CheckRun::class);
    }

    public function previousIpsLabel(): string
    {
        return $this->ipSetLabel($this->previous_ips ?? []);
    }

    public function currentIpsLabel(): string
    {
        return $this->ipSetLabel($this->current_ips ?? []);
    }

    public function previousCnamesLabel(): string
    {
        return $this->cnameSetLabel($this->previous_cnames);
    }

    public function currentCnamesLabel(): string
    {
        return $this->cnameSetLabel($this->current_cnames);
    }

    public function previousRecordsLabel(): string
    {
        return $this->dnsRecordsLabel($this->previous_records);
    }

    public function currentRecordsLabel(): string
    {
        return $this->dnsRecordsLabel($this->current_records);
    }

    /** @param list<string> $ips */
    private function ipSetLabel(array $ips): string
    {
        return $ips === [] ? '無公開 IP 回答' : implode(', ', $ips);
    }

    /** @param list<string>|null $cnames */
    private function cnameSetLabel(?array $cnames): string
    {
        if ($cnames === null) {
            return '未記錄';
        }

        return $cnames === [] ? '無 CNAME 記錄' : implode(', ', $cnames);
    }

    /** @param list<array{type: string, value: string}>|null $records */
    private function dnsRecordsLabel(?array $records): string
    {
        if ($records === null) {
            return '未記錄';
        }

        if ($records === []) {
            return '無其他 DNS 記錄';
        }

        return implode('; ', array_map(
            static fn (array $record): string => $record['type'].': '.$record['value'],
            $records,
        ));
    }
}
