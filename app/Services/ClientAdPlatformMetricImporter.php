<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAdPlatformDailyMetric;

/**
 * Imports daily campaign reports exported from TikTok Ads Manager or the LINE 廣告 (LAP) dashboard.
 *
 * Each campaign-day is stored once; importing an overlapping report replaces that day's figures, so
 * re-exporting a whole month after late conversions arrive simply corrects the numbers.
 */
class ClientAdPlatformMetricImporter
{
    public const MAX_ROWS = 20000;

    private const MAX_REPORTED_ERRORS = 20;

    private const UNNAMED_CAMPAIGN = '（未分類）';

    /** @var array<string, list<string>> */
    public const FIELD_ALIASES = [
        'date' => ['date', 'day', 'by day', 'stat time day', 'reporting starts', '日期', '日', '報表日期', '統計日期', '時間'],
        'campaign' => ['campaign name', 'campaign', 'ad group name', '廣告活動名稱', '廣告活動', '活動名稱', '活動', '行銷活動名稱', '廣告組合名稱'],
        'spend' => ['cost', 'spend', 'total cost', 'amount spent', '花費', '費用', '總花費', '花費金額', '成本', '廣告費用', '消耗', '已花費金額'],
        'impressions' => ['impressions', 'impression', '曝光', '曝光數', '曝光次數', '展示次數', '顯示次數'],
        'clicks' => ['clicks', 'clicks (destination)', 'clicks (all)', 'link clicks', '點擊', '點擊數', '點擊次數', '連結點擊'],
        'conversions' => ['conversions', 'result', 'results', 'total purchase', 'purchases', 'complete payment', '轉換', '轉換數', '轉換次數', '成果', '購買', '購買次數', '完成付款'],
        'conversion_value' => ['conversion value', 'total complete payment value', 'purchase value', 'total purchase value', 'value', '轉換價值', '轉換金額', '購買價值', '購買金額', '完成付款價值', '營收'],
        'currency' => ['currency', 'currency code', '幣別', '貨幣'],
    ];

    public function __construct(private readonly MarketingCsvReader $csvReader) {}

    /**
     * @return array{imported: int, campaigns: int, errors: list<array{line: int, message: string}>, error_count: int, truncated: bool, from: string|null, to: string|null, spend_by_currency: array<string, float>}
     *
     * @throws MarketingCsvException
     */
    public function import(Client $client, string $platform, string $path, string $defaultCurrencyCode): array
    {
        $csv = $this->csvReader->read($path, self::FIELD_ALIASES, self::MAX_ROWS);
        $missing = array_diff(['date', 'spend'], array_keys($csv['columns']));

        if ($missing !== []) {
            throw new MarketingCsvException('找不到'.(in_array('date', $missing, true) ? '日期' : '花費').'欄位。請在廣告後台匯出「依日期」細分的報表，標題列需包含日期與花費（Cost）。');
        }

        $records = [];
        $errors = [];
        $errorCount = 0;

        foreach ($csv['rows'] as $row) {
            $cells = $row['cells'];
            $date = MarketingCsvReader::date($cells['date']);
            $spend = MarketingCsvReader::number($cells['spend']);
            $currencyCode = strtoupper(trim($cells['currency'] ?? '')) ?: $defaultCurrencyCode;
            $message = match (true) {
                $date === null => '日期格式無法辨識：'.mb_substr($cells['date'], 0, 30),
                $spend === null || $spend < 0 => '花費無效：'.mb_substr($cells['spend'], 0, 30),
                preg_match('/^[A-Z]{3}$/', $currencyCode) !== 1 => '幣別必須是 3 碼英文代碼。',
                default => null,
            };

            if ($message !== null) {
                $errorCount++;

                if (count($errors) < self::MAX_REPORTED_ERRORS) {
                    $errors[] = ['line' => $row['line'], 'message' => $message];
                }

                continue;
            }

            $campaign = mb_substr(trim($cells['campaign'] ?? ''), 0, 191) ?: self::UNNAMED_CAMPAIGN;
            $key = $date."\0".$campaign;
            $record = $records[$key] ?? [
                'metric_on' => $date,
                'campaign_name' => $campaign,
                'currency_code' => $currencyCode,
                'spend' => 0.0,
                'impressions' => 0,
                'clicks' => 0,
                'conversions' => 0.0,
                'conversion_value' => 0.0,
            ];
            $record['spend'] += $spend;
            $record['impressions'] += max(0, (int) round(MarketingCsvReader::number($cells['impressions'] ?? '') ?? 0));
            $record['clicks'] += max(0, (int) round(MarketingCsvReader::number($cells['clicks'] ?? '') ?? 0));
            $record['conversions'] += max(0.0, MarketingCsvReader::number($cells['conversions'] ?? '') ?? 0.0);
            $record['conversion_value'] += max(0.0, MarketingCsvReader::number($cells['conversion_value'] ?? '') ?? 0.0);
            $records[$key] = $record;
        }

        $now = now();
        $rows = array_map(static fn (array $record): array => [
            'client_id' => $client->getKey(),
            'platform' => $platform,
            'metric_on' => $record['metric_on'],
            'campaign_name' => $record['campaign_name'],
            'currency_code' => $record['currency_code'],
            'spend' => number_format($record['spend'], 2, '.', ''),
            'impressions' => $record['impressions'],
            'clicks' => $record['clicks'],
            'conversions' => number_format($record['conversions'], 4, '.', ''),
            'conversion_value' => number_format($record['conversion_value'], 2, '.', ''),
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values($records));

        foreach (array_chunk($rows, 500) as $chunk) {
            ClientAdPlatformDailyMetric::query()->upsert(
                $chunk,
                ['client_id', 'platform', 'metric_on', 'campaign_name'],
                ['currency_code', 'spend', 'impressions', 'clicks', 'conversions', 'conversion_value', 'updated_at'],
            );
        }

        $dates = array_column($rows, 'metric_on');
        $spendByCurrency = [];

        foreach ($records as $record) {
            $spendByCurrency[$record['currency_code']] = round(($spendByCurrency[$record['currency_code']] ?? 0.0) + $record['spend'], 2);
        }

        ksort($spendByCurrency);

        return [
            'imported' => count($rows),
            'campaigns' => count(array_unique(array_column($rows, 'campaign_name'))),
            'errors' => $errors,
            'error_count' => $errorCount,
            'truncated' => $csv['truncated'],
            'from' => $dates === [] ? null : min($dates),
            'to' => $dates === [] ? null : max($dates),
            'spend_by_currency' => $spendByCurrency,
        ];
    }

    /**
     * Loads imported campaigns in the shape ClientCrossPlatformOverview::build() expects.
     *
     * @return array<string, list<array{currency_code: string, campaign_name: string, rows: list<array<string, mixed>>}>>
     */
    public function campaignsForPeriod(Client $client, string $fromOn, string $toOn): array
    {
        $metrics = $client->adPlatformDailyMetrics()
            ->whereDate('metric_on', '>=', $fromOn)
            ->whereDate('metric_on', '<=', $toOn)
            ->orderBy('metric_on')
            ->get();
        $campaigns = [];

        foreach ($metrics as $metric) {
            /** @var ClientAdPlatformDailyMetric $metric */
            $key = $metric->currency_code.'|'.$metric->campaign_name;
            $campaigns[$metric->platform][$key] ??= [
                'currency_code' => $metric->currency_code,
                'campaign_name' => $metric->campaign_name,
                'rows' => [],
            ];
            $campaigns[$metric->platform][$key]['rows'][] = [
                'date' => $metric->metric_on->toDateString(),
                'spend_amount' => (string) $metric->spend,
                'impressions' => $metric->impressions,
                'clicks' => $metric->clicks,
                'conversions' => (string) $metric->conversions,
                'conversion_value' => (string) $metric->conversion_value,
            ];
        }

        return array_map(static fn (array $platformCampaigns): array => array_values($platformCampaigns), $campaigns);
    }
}
