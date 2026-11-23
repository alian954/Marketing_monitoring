<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'google_analytics_connection_id',
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
    'daily_metrics',
    'synced_at',
])]
#[Hidden(['landing_page_report', 'landing_page_expires_at'])]
class SocialEngagementTargetGoogleAnalyticsReport extends Model
{
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
            'daily_metrics' => 'array',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
