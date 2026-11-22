<?php

namespace App\Models;

use Database\Factories\SocialEngagementTargetGoogleAdsReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'google_ads_connection_id',
    'social_engagement_target_id',
    'customer_id',
    'campaign_id',
    'campaign_name',
    'starts_on',
    'ends_on',
    'spend_amount',
    'impressions',
    'clicks',
    'conversions',
    'conversions_value',
    'currency_code',
    'synced_at',
])]
class SocialEngagementTargetGoogleAdsReport extends Model
{
    /** @use HasFactory<SocialEngagementTargetGoogleAdsReportFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'spend_amount' => 'decimal:4',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'conversions' => 'decimal:4',
            'conversions_value' => 'decimal:4',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
