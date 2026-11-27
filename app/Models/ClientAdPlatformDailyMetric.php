<?php

namespace App\Models;

use Database\Factories\ClientAdPlatformDailyMetricFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One campaign-day of performance imported from an ad platform report CSV (TikTok Ads, LINE 廣告).
 */
#[Fillable(['platform', 'metric_on', 'campaign_name', 'currency_code', 'spend', 'impressions', 'clicks', 'conversions', 'conversion_value'])]
class ClientAdPlatformDailyMetric extends Model
{
    public const PLATFORM_TIKTOK = 'tiktok_ads';

    public const PLATFORM_LINE = 'line_ads';

    /** @use HasFactory<ClientAdPlatformDailyMetricFactory> */
    use HasFactory;

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected function casts(): array
    {
        return [
            'metric_on' => 'immutable_date',
            'spend' => 'decimal:2',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'conversions' => 'decimal:4',
            'conversion_value' => 'decimal:2',
        ];
    }
}
