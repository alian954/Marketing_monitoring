<?php

namespace App\Models;

use Database\Factories\SocialEngagementTargetGoogleAdsReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
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
    'conversion_actions',
    'daily_metrics',
    'search_terms_data',
    'search_terms_expires_at',
    'delivery_unit_performance',
    'delivery_unit_performance_expires_at',
    'device_performance',
    'device_performance_expires_at',
    'currency_code',
    'synced_at',
])]
#[Hidden(['search_terms_data', 'search_terms_expires_at', 'delivery_unit_performance', 'delivery_unit_performance_expires_at', 'device_performance', 'device_performance_expires_at'])]
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
            'conversion_actions' => 'array',
            'daily_metrics' => 'array',
            'search_terms_data' => 'encrypted:array',
            'search_terms_expires_at' => 'immutable_datetime',
            'delivery_unit_performance' => 'encrypted:array',
            'delivery_unit_performance_expires_at' => 'immutable_datetime',
            'device_performance' => 'encrypted:array',
            'device_performance_expires_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
