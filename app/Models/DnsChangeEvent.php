<?php

namespace App\Models;

use Database\Factories\DnsChangeEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['monitor_id', 'check_run_id', 'previous_ips', 'current_ips', 'monitor_host_only', 'detected_at'])]
class DnsChangeEvent extends Model
{
    /** @use HasFactory<DnsChangeEventFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'previous_ips' => 'array',
            'current_ips' => 'array',
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
}
