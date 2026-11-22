<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'google_analytics_connection_id',
    'social_engagement_target_id',
    'campaign_name',
    'source_name',
    'medium_name',
    'starts_on',
    'ends_on',
    'sessions',
    'engaged_sessions',
    'key_events',
    'total_revenue',
    'currency_code',
    'synced_at',
])]
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
            'synced_at' => 'immutable_datetime',
        ];
    }
}
