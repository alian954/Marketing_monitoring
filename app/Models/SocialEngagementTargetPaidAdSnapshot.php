<?php

namespace App\Models;

use Database\Factories\SocialEngagementTargetPaidAdSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'social_engagement_target_id',
    'meta_marketing_connection_id',
    'google_ads_connection_id',
    'source',
    'account_id',
    'campaign_id',
    'campaign_name',
    'starts_on',
    'ends_on',
    'spend_amount',
    'impressions',
    'reach',
    'clicks',
    'conversions',
    'conversions_value',
    'currency_code',
    'synced_at',
])]
class SocialEngagementTargetPaidAdSnapshot extends Model
{
    public const SOURCE_META_MARKETING = 'meta_marketing';

    public const SOURCE_GOOGLE_ADS = 'google_ads';

    /** @use HasFactory<SocialEngagementTargetPaidAdSnapshotFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'spend_amount' => 'decimal:4',
            'impressions' => 'integer',
            'reach' => 'integer',
            'clicks' => 'integer',
            'conversions' => 'decimal:4',
            'conversions_value' => 'decimal:4',
            'synced_at' => 'immutable_datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(SocialEngagementTarget::class, 'social_engagement_target_id');
    }

    public function metaMarketingConnection(): BelongsTo
    {
        return $this->belongsTo(MetaMarketingConnection::class);
    }

    public function googleAdsConnection(): BelongsTo
    {
        return $this->belongsTo(GoogleAdsConnection::class);
    }
}
