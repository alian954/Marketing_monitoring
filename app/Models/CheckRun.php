<?php

namespace App\Models;

use Database\Factories\CheckRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['monitor_id', 'run_key', 'config_version', 'config_snapshot', 'source', 'status', 'scheduled_at', 'started_at', 'finished_at', 'normal_status', 'adsbot_status', 'error_type', 'error_detail', 'adsbot_error_type', 'elapsed_ms', 'normal_elapsed_ms', 'final_url', 'redirects', 'resolved_public_ips', 'monitor_host_ips', 'expected_text_found', 'tls_expires_at'])]
class CheckRun extends Model
{
    /** @use HasFactory<CheckRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'config_snapshot' => 'encrypted',
            'config_version' => 'integer',
            'normal_status' => 'integer',
            'adsbot_status' => 'integer',
            'elapsed_ms' => 'integer',
            'normal_elapsed_ms' => 'integer',
            'scheduled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'redirects' => 'array',
            'resolved_public_ips' => 'array',
            'monitor_host_ips' => 'array',
            'expected_text_found' => 'boolean',
            'tls_expires_at' => 'immutable_datetime',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function incidentEvents(): HasMany
    {
        return $this->hasMany(IncidentEvent::class);
    }
}
