<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'meta_marketing_connection_id',
    'social_engagement_target_id',
    'ad_account_id',
    'campaign_id',
    'campaign_name',
    'starts_on',
    'ends_on',
    'spend_amount',
    'impressions',
    'reach',
    'clicks',
    'actions',
    'action_values',
    'daily_metrics',
    'ad_insights',
    'ad_insights_expires_at',
    'currency_code',
    'synced_at',
])]
#[Hidden(['ad_insights', 'ad_insights_expires_at'])]
class SocialEngagementTargetMetaMarketingReport extends Model
{
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'spend_amount' => 'decimal:4',
            'impressions' => 'integer',
            'reach' => 'integer',
            'clicks' => 'integer',
            'actions' => 'array',
            'action_values' => 'array',
            'daily_metrics' => 'array',
            'ad_insights' => 'encrypted:array',
            'ad_insights_expires_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
