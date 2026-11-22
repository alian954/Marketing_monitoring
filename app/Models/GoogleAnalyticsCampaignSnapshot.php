<?php

namespace App\Models;

use Database\Factories\GoogleAnalyticsCampaignSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'google_analytics_connection_id',
    'social_engagement_target_id',
    'snapshot_date',
    'campaign_name',
    'source_name',
    'starts_on',
    'ends_on',
    'sessions',
    'engaged_sessions',
    'key_events',
    'total_revenue',
    'currency_code',
    'synced_at',
])]
class GoogleAnalyticsCampaignSnapshot extends Model
{
    /** @use HasFactory<GoogleAnalyticsCampaignSnapshotFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'sessions' => 'integer',
            'engaged_sessions' => 'integer',
            'key_events' => 'decimal:4',
            'total_revenue' => 'decimal:4',
            'synced_at' => 'immutable_datetime',
        ];
    }

    public function googleAnalyticsConnection(): BelongsTo
    {
        return $this->belongsTo(GoogleAnalyticsConnection::class);
    }

    public function socialEngagementTarget(): BelongsTo
    {
        return $this->belongsTo(SocialEngagementTarget::class);
    }
}
