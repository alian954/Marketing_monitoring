<?php

namespace App\Models;

use Database\Factories\RobotsObservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['monitor_id', 'observed_on', 'config_version', 'file_state', 'http_status', 'error_type', 'policy_hash', 'crawler_decisions', 'rule_count', 'sitemap_count', 'policy_changed', 'checked_at'])]
class RobotsObservation extends Model
{
    /** @use HasFactory<RobotsObservationFactory> */
    use HasFactory;

    use ScopesThroughAuthenticatedMonitor;

    protected function casts(): array
    {
        return [
            'observed_on' => 'immutable_date',
            'config_version' => 'integer',
            'http_status' => 'integer',
            'crawler_decisions' => 'array',
            'rule_count' => 'integer',
            'sitemap_count' => 'integer',
            'policy_changed' => 'boolean',
            'checked_at' => 'immutable_datetime',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
