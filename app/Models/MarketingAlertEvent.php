<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['social_engagement_target_id', 'source', 'observed_on', 'observed_value', 'currency_code', 'message', 'notification_suppressed_at'])]
class MarketingAlertEvent extends Model
{
    public function rule(): BelongsTo
    {
        return $this->belongsTo(MarketingAlertRule::class, 'marketing_alert_rule_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(SocialEngagementTarget::class, 'social_engagement_target_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    public function wasNotificationSuppressed(): bool
    {
        return $this->notification_suppressed_at !== null;
    }

    /**
     * @param  Builder<MarketingAlertEvent>  $query
     * @return Builder<MarketingAlertEvent>
     */
    public function scopeUnacknowledged(Builder $query): Builder
    {
        return $query->whereNull('acknowledged_at');
    }

    protected function casts(): array
    {
        return [
            'observed_on' => 'immutable_date',
            'observed_value' => 'decimal:4',
            'acknowledged_at' => 'immutable_datetime',
            'muted_until' => 'immutable_date',
            'notification_suppressed_at' => 'immutable_datetime',
        ];
    }
}
