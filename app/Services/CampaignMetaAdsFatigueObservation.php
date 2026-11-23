<?php

namespace App\Services;

use App\Models\SocialEngagementTarget;
use Carbon\CarbonImmutable;

class CampaignMetaAdsFatigueObservation
{
    private const PERIOD_DAYS = 7;

    private const FREQUENCY_INCREASE_THRESHOLD_PERCENT = 20.0;

    private const CTR_DECREASE_THRESHOLD_PERCENT = 20.0;

    /**
     * @param  array<string, mixed>  $dailyMarketing
     * @return array{
     *     status: string,
     *     status_label: string,
     *     current_period: array{start_on: string|null, end_on: string|null, average_frequency: float|null, ctr_percent: float|null, impressions: int, delivery_day_count: int},
     *     previous_period: array{start_on: string|null, end_on: string|null, average_frequency: float|null, ctr_percent: float|null, impressions: int, delivery_day_count: int},
     *     frequency_change_percent: float|null,
     *     ctr_change_percent: float|null,
     *     frequency_threshold_percent: float,
     *     ctr_threshold_percent: float,
     *     message: string
     * }
     */
    public function build(
        SocialEngagementTarget $target,
        array $dailyMarketing,
        bool $isStale,
    ): array {
        if (! is_string($target->meta_campaign_id) || trim($target->meta_campaign_id) === '') {
            return $this->unavailable('not_configured');
        }

        $campaignStartOn = $target->starts_on?->toDateString();
        $campaignEndOn = $target->ends_on?->toDateString();

        if ($campaignStartOn === null || $campaignEndOn === null || $campaignStartOn > $campaignEndOn) {
            return $this->unavailable('missing_period');
        }

        $comparisonEndOn = min(CarbonImmutable::now('Asia/Taipei')->subDay()->toDateString(), $campaignEndOn);
        $comparisonEnd = $this->date($comparisonEndOn);

        if ($comparisonEnd === null || $comparisonEndOn < $campaignStartOn) {
            return $this->unavailable('insufficient_period');
        }

        $currentEndOn = $comparisonEnd->toDateString();
        $currentStartOn = $comparisonEnd->subDays(self::PERIOD_DAYS - 1)->toDateString();
        $previousEndOn = $comparisonEnd->subDays(self::PERIOD_DAYS)->toDateString();
        $previousStartOn = $comparisonEnd->subDays((self::PERIOD_DAYS * 2) - 1)->toDateString();
        $emptyPeriods = [
            $this->emptyPeriod($currentStartOn, $currentEndOn),
            $this->emptyPeriod($previousStartOn, $previousEndOn),
        ];

        if ($previousStartOn < $campaignStartOn) {
            return $this->unavailable('insufficient_period', ...$emptyPeriods);
        }

        if (($dailyMarketing['snapshot_available'] ?? false) !== true) {
            return $this->unavailable('missing_data', ...$emptyPeriods);
        }

        if (($dailyMarketing['status'] ?? null) !== 'complete') {
            $status = match ($dailyMarketing['status'] ?? null) {
                'partial' => 'partial',
                'stale' => 'stale',
                'unsupported' => 'unsupported',
                default => 'missing_data',
            };

            return $this->unavailable($status, ...$emptyPeriods);
        }

        if ($isStale) {
            return $this->unavailable('stale', ...$emptyPeriods);
        }

        $periodRows = $this->periodRows($dailyMarketing['rows'] ?? null, $previousStartOn, $currentEndOn);

        if ($periodRows === null) {
            return $this->unavailable('partial', ...$emptyPeriods);
        }

        if ($periodRows === false) {
            return $this->unavailable('invalid_metrics', ...$emptyPeriods);
        }

        $currentRows = [];
        $previousRows = [];

        foreach ($periodRows as $date => $row) {
            if ($date >= $currentStartOn) {
                $currentRows[] = $row;
            } else {
                $previousRows[] = $row;
            }
        }

        $currentPeriod = $this->summarizePeriod($currentRows, $currentStartOn, $currentEndOn);
        $previousPeriod = $this->summarizePeriod($previousRows, $previousStartOn, $previousEndOn);

        if ($currentPeriod === null || $previousPeriod === null) {
            return $this->unavailable('invalid_metrics', ...$emptyPeriods);
        }

        $frequencyChangePercent = $this->changePercent(
            $previousPeriod['average_frequency'],
            $currentPeriod['average_frequency'],
        );
        $ctrChangePercent = $this->changePercent(
            $previousPeriod['ctr_percent'],
            $currentPeriod['ctr_percent'],
        );
        $hasFrequencyIncrease = $frequencyChangePercent !== null
            && $frequencyChangePercent + 0.000001 >= self::FREQUENCY_INCREASE_THRESHOLD_PERCENT;
        $hasCtrDecrease = $ctrChangePercent !== null
            && $ctrChangePercent - 0.000001 <= -self::CTR_DECREASE_THRESHOLD_PERCENT;

        return [
            'status' => $hasFrequencyIncrease && $hasCtrDecrease ? 'signal' : 'no_signal',
            'status_label' => $hasFrequencyIncrease && $hasCtrDecrease ? '建議檢視' : '未達提示條件',
            'message' => $hasFrequencyIncrease && $hasCtrDecrease
                ? '頻率增加與 CTR 下滑同時達到提示門檻，建議檢視素材與受眾。'
                : '目前未同時達到兩項提示門檻。',
            'current_period' => $currentPeriod,
            'previous_period' => $previousPeriod,
            'frequency_change_percent' => $frequencyChangePercent === null ? null : round($frequencyChangePercent, 2),
            'ctr_change_percent' => $ctrChangePercent === null ? null : round($ctrChangePercent, 2),
            'frequency_threshold_percent' => self::FREQUENCY_INCREASE_THRESHOLD_PERCENT,
            'ctr_threshold_percent' => self::CTR_DECREASE_THRESHOLD_PERCENT,
        ];
    }

    /** @return array<string, array{impressions: int, clicks: int, frequency: float|null}>|false|null */
    private function periodRows(mixed $rows, string $startOn, string $endOn): array|false|null
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            return null;
        }

        $rowsByDate = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = $this->validDate($row['date'] ?? null);

            if ($date === null || $date < $startOn || $date > $endOn) {
                continue;
            }

            if (array_key_exists($date, $rowsByDate)) {
                return null;
            }

            $impressions = $row['impressions'] ?? null;
            $reach = $row['reach'] ?? null;
            $clicks = $row['clicks'] ?? null;

            if (! is_int($impressions) || $impressions < 0
                || ! is_int($reach) || $reach < 0
                || ! is_int($clicks) || $clicks < 0) {
                return false;
            }

            if (($impressions === 0 && ($reach !== 0 || $clicks !== 0))
                || ($impressions > 0 && $reach === 0)) {
                return false;
            }

            $rowsByDate[$date] = [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'frequency' => $impressions > 0 ? $impressions / $reach : null,
            ];
        }

        $start = $this->date($startOn);
        $end = $this->date($endOn);

        if ($start === null || $end === null) {
            return false;
        }

        for ($day = $start; $day <= $end; $day = $day->addDay()) {
            if (! array_key_exists($day->toDateString(), $rowsByDate)) {
                return null;
            }
        }

        ksort($rowsByDate);

        return $rowsByDate;
    }

    /**
     * @param  list<array{impressions: int, clicks: int, frequency: float|null}>  $rows
     * @return array{start_on: string, end_on: string, average_frequency: float, ctr_percent: float, impressions: int, delivery_day_count: int}|null
     */
    private function summarizePeriod(array $rows, string $startOn, string $endOn): ?array
    {
        if (count($rows) !== self::PERIOD_DAYS) {
            return null;
        }

        $impressions = array_sum(array_column($rows, 'impressions'));
        $clicks = array_sum(array_column($rows, 'clicks'));
        $frequencies = array_values(array_filter(
            array_column($rows, 'frequency'),
            static fn (?float $frequency): bool => $frequency !== null,
        ));

        if ($impressions <= 0 || $frequencies === []) {
            return null;
        }

        return [
            'start_on' => $startOn,
            'end_on' => $endOn,
            'average_frequency' => (float) array_sum($frequencies) / count($frequencies),
            'ctr_percent' => $clicks / $impressions * 100,
            'impressions' => $impressions,
            'delivery_day_count' => count($frequencies),
        ];
    }

    private function changePercent(float $previousValue, float $currentValue): ?float
    {
        if ($previousValue <= 0) {
            return null;
        }

        return ($currentValue / $previousValue - 1) * 100;
    }

    /** @return array<string, mixed> */
    private function unavailable(
        string $status,
        ?array $currentPeriod = null,
        ?array $previousPeriod = null,
    ): array {
        return [
            'status' => $status,
            'status_label' => match ($status) {
                'not_configured' => '尚未設定 Meta 活動',
                'missing_period' => '請先設定活動日期',
                'insufficient_period' => '不足 14 天資料',
                'stale' => 'Meta 資料已過期',
                'partial' => '逐日資料不完整',
                'unsupported' => '平台未提供所需資料',
                'invalid_metrics' => '頻率或點擊率指標不足',
                default => '尚無逐日資料',
            },
            'message' => match ($status) {
                'not_configured' => '尚未設定 Meta 活動 ID，請先完成活動連結。',
                'missing_period' => '請先設定活動開始日與結束日。',
                'insufficient_period' => '活動日期不足兩個完整七日區間，暫不判讀。',
                'stale' => 'Meta 數據超過 24 小時未更新，請同步後再判讀。',
                'partial' => '部分比較日期缺少逐日資料，補齊同步資料後再判讀。',
                'unsupported' => 'Meta 未提供所需的頻率、觸及或點擊逐日資料。',
                'invalid_metrics' => '至少一天的曝光、觸及或點擊無法形成有效比率，暫不判讀。',
                default => '尚無 Meta 逐日資料，完成同步後即可比較。',
            },
            'current_period' => $currentPeriod ?? $this->emptyPeriod(null, null),
            'previous_period' => $previousPeriod ?? $this->emptyPeriod(null, null),
            'frequency_change_percent' => null,
            'ctr_change_percent' => null,
            'frequency_threshold_percent' => self::FREQUENCY_INCREASE_THRESHOLD_PERCENT,
            'ctr_threshold_percent' => self::CTR_DECREASE_THRESHOLD_PERCENT,
        ];
    }

    /** @return array{start_on: string|null, end_on: string|null, average_frequency: null, ctr_percent: null, impressions: int, delivery_day_count: int} */
    private function emptyPeriod(?string $startOn, ?string $endOn): array
    {
        return [
            'start_on' => $startOn,
            'end_on' => $endOn,
            'average_frequency' => null,
            'ctr_percent' => null,
            'impressions' => 0,
            'delivery_day_count' => 0,
        ];
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return $this->date($value)?->format('Y-m-d') === $value ? $value : null;
    }

    private function date(string $value): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Asia/Taipei');
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
