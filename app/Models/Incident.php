<?php

namespace App\Models;

use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['monitor_id', 'config_version', 'rule_key', 'severity', 'state', 'classification', 'acknowledged_at', 'acknowledged_by_id', 'snoozed_until', 'active_key', 'note', 'failure_count', 'recovery_count', 'confirm_after', 'opened_at', 'last_seen_at', 'resolved_at'])]
class Incident extends Model
{
    public const STATE_SUSPECT = 'suspect';

    public const STATE_OPEN = 'open';

    public const STATE_RESOLVED = 'resolved';

    public const STATE_PAUSED = 'paused';

    public const STATE_SUPERSEDED = 'superseded';

    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'config_version' => 'integer',
            'acknowledged_by_id' => 'integer',
            'acknowledged_at' => 'immutable_datetime',
            'snoozed_until' => 'immutable_datetime',
            'failure_count' => 'integer',
            'recovery_count' => 'integer',
            'confirm_after' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(IncidentEvent::class)->orderBy('created_at');
    }

    public function outboxMessages(): HasMany
    {
        return $this->hasMany(NotificationOutbox::class);
    }
}
