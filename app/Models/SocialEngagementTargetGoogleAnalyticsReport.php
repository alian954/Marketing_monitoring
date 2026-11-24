<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'google_analytics_connection_id',
    'property_id',
    'social_engagement_target_id',
    'campaign_name',
    'attribution_mode',
    'campaign_id',
    'source_name',
    'medium_name',
    'starts_on',
    'ends_on',
    'sessions',
    'engaged_sessions',
    'key_events',
    'total_revenue',
    'currency_code',
    'source_medium_breakdown',
    'source_medium_breakdown_truncated',
    'landing_page_report',
    'landing_page_expires_at',
    'event_name_report',
    'event_name_expires_at',
    'item_report',
    'item_report_expires_at',
    'ad_content_report',
    'ad_content_report_expires_at',
    'new_vs_returning_report',
    'new_vs_returning_report_expires_at',
    'daily_metrics',
    'synced_at',
])]
#[Hidden(['landing_page_report', 'landing_page_expires_at', 'event_name_report', 'event_name_expires_at', 'item_report', 'item_report_expires_at', 'ad_content_report', 'ad_content_report_expires_at', 'new_vs_returning_report', 'new_vs_returning_report_expires_at'])]
class SocialEngagementTargetGoogleAnalyticsReport extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new AuthenticatedRelationScope('socialEngagementTarget'));
    }

    public function socialEngagementTarget(): BelongsTo
    {
        return $this->belongsTo(SocialEngagementTarget::class);
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'sessions' => 'integer',
            'engaged_sessions' => 'integer',
            'key_events' => 'decimal:4',
            'total_revenue' => 'decimal:4',
            'source_medium_breakdown' => 'array',
            'source_medium_breakdown_truncated' => 'boolean',
            'landing_page_report' => 'encrypted:array',
            'landing_page_expires_at' => 'immutable_datetime',
            'event_name_report' => 'encrypted:array',
            'event_name_expires_at' => 'immutable_datetime',
            'item_report' => 'encrypted:array',
            'item_report_expires_at' => 'immutable_datetime',
            'ad_content_report' => 'encrypted:array',
            'ad_content_report_expires_at' => 'immutable_datetime',
            'new_vs_returning_report' => 'encrypted:array',
            'new_vs_returning_report_expires_at' => 'immutable_datetime',
            'daily_metrics' => 'array',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
