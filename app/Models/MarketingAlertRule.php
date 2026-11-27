<?php

namespace App\Models;

use Database\Factories\MarketingAlertRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['metric', 'threshold', 'recipients', 'webhook_url', 'webhook_channel', 'line_notification_target_id', 'paused_at'])]
#[Hidden(['recipients', 'webhook_url'])]
class MarketingAlertRule extends Model
{
    public const METRIC_SPEND_SPIKE = 'spend_spike';

    public const METRIC_CPA_ABOVE = 'cpa_above';

    public const METRIC_ROAS_BELOW = 'roas_below';

    public const METRIC_CONVERSIONS_STOPPED = 'conversions_stopped';

    public const METRIC_BUDGET_OVERPACE = 'budget_overpace';

    public const METRIC_SYNC_STALE = 'sync_stale';

    public const METRIC_ANOMALY = 'anomaly';

    /**
     * @var array<string, array{label: string, threshold_label: string|null, default_threshold: string|null, help: string}>
     */
    public const METRICS = [
        self::METRIC_SPEND_SPIKE => [
            'label' => '單日花費暴增',
            'threshold_label' => '高於前 7 日平均（%）',
            'default_threshold' => '50',
            'help' => '昨日廣告花費比前 7 日平均高出設定百分比；至少需 3 天歷史資料。',
        ],
        self::METRIC_CPA_ABOVE => [
            'label' => 'CPA 高於門檻',
            'threshold_label' => '每次轉換成本上限（廣告帳戶幣別）',
            'default_threshold' => null,
            'help' => '昨日每次購買或轉換成本高於門檻。',
        ],
        self::METRIC_ROAS_BELOW => [
            'label' => 'ROAS 低於門檻',
            'threshold_label' => '最低 ROAS（倍）',
            'default_threshold' => '1',
            'help' => '昨日有花費且廣告回報率低於門檻。',
        ],
        self::METRIC_CONVERSIONS_STOPPED => [
            'label' => '轉換歸零',
            'threshold_label' => null,
            'default_threshold' => null,
            'help' => '昨日有花費但沒有轉換，而前 7 日至少有 3 次轉換，常見於追蹤碼或結帳流程故障。',
        ],
        self::METRIC_BUDGET_OVERPACE => [
            'label' => '預算超前消耗',
            'threshold_label' => '超過時間進度（%）',
            'default_threshold' => '10',
            'help' => '累計花費超過依活動天數換算的預期花費，或已達活動預算；同一活動只通知一次。',
        ],
        self::METRIC_ANOMALY => [
            'label' => '數據偏離平常範圍',
            'threshold_label' => '偏離幾個標準差',
            'default_threshold' => '3',
            'help' => '昨日花費、轉換、CPA 或 CTR 偏離該活動前 14 日的平常範圍；不必替每個客戶設定固定門檻，至少需 7 天歷史資料。',
        ],
        self::METRIC_SYNC_STALE => [
            'label' => '廣告數據未更新',
            'threshold_label' => null,
            'default_threshold' => null,
            'help' => '已綁定廣告活動，但超過 36 小時沒有成功同步。',
        ],
    ];

    /** @use HasFactory<MarketingAlertRuleFactory> */
    use HasFactory;

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lineNotificationTarget(): BelongsTo
    {
        return $this->belongsTo(LineNotificationTarget::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MarketingAlertEvent::class);
    }

    public function label(): string
    {
        return self::METRICS[$this->metric]['label'] ?? $this->metric;
    }

    public function requiresThreshold(): bool
    {
        return (self::METRICS[$this->metric]['threshold_label'] ?? null) !== null;
    }

    /** @return list<string> */
    public function recipientList(): array
    {
        return array_values(array_filter(
            explode("\n", (string) $this->recipients),
            static fn (string $recipient): bool => $recipient !== '',
        ));
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    protected function casts(): array
    {
        return [
            'threshold' => 'decimal:4',
            'recipients' => 'encrypted',
            'webhook_url' => 'encrypted',
            'paused_at' => 'immutable_datetime',
            'last_triggered_at' => 'immutable_datetime',
        ];
    }
}
