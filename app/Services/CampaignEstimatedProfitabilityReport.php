<?php

namespace App\Services;

use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;

class CampaignEstimatedProfitabilityReport
{
    /**
     * @param  array<string, mixed>  $adSpendAnalysis
     * @return array{
     *     gross_margin_percent: float|null,
     *     gross_profit_amount: float|null,
     *     media_contribution_amount: float|null,
     *     break_even_roas: float|null,
     *     break_even_roas_status: string,
     *     break_even_roas_status_label: string,
     *     currency_code: string|null,
     *     status: string,
     *     status_label: string
     * }
     */
    public function build(
        SocialEngagementTarget $target,
        ?SocialEngagementTargetGoogleAnalyticsReport $googleAnalyticsReport,
        array $adSpendAnalysis,
        bool $googleAnalyticsSnapshotIsFresh,
        bool $spendSnapshotIsFresh,
    ): array {
        $marginPercent = $this->finiteNumber($target->gross_margin_percent);
        $base = [
            'gross_margin_percent' => $marginPercent,
            'gross_profit_amount' => null,
            'media_contribution_amount' => null,
            'break_even_roas' => null,
            'break_even_roas_status' => 'unavailable',
            'break_even_roas_status_label' => '目前無法計算',
            'currency_code' => null,
        ];
        $status = match (true) {
            $target->gross_margin_percent === null => 'not_configured',
            $marginPercent === null || $marginPercent < 0 || $marginPercent > 100 => 'invalid_margin',
            $googleAnalyticsReport === null => 'missing_ga4',
            ! $googleAnalyticsSnapshotIsFresh => 'stale',
            default => null,
        };

        if ($status !== null) {
            return [
                ...$base,
                'break_even_roas_status' => $status,
                'break_even_roas_status_label' => $this->breakEvenStatusLabel($status),
                'status' => $status,
                'status_label' => $this->statusLabel($status),
            ];
        }

        $revenue = $this->finiteNumber($googleAnalyticsReport->total_revenue);
        $spendAmount = $this->finiteNumber($adSpendAnalysis['ad_spend_amount'] ?? null);
        $spendSource = $adSpendAnalysis['ad_spend_source'] ?? null;
        $spendCurrency = $this->currencyCode($adSpendAnalysis['ad_spend_currency'] ?? null);
        $revenueCurrency = $this->currencyCode($googleAnalyticsReport->currency_code);
        $status = match (true) {
            $revenue === null => 'missing_revenue',
            in_array($adSpendAnalysis['ad_spend_source'] ?? null, ['currency_mismatch'], true)
                || ($adSpendAnalysis['platform_currency_mismatch'] ?? false) === true => 'currency_mismatch',
            ! in_array($spendSource, ['meta_marketing', 'google_ads', 'combined_paid_ads', 'manual'], true)
                || $spendAmount === null
                || $spendAmount < 0 => 'missing_spend',
            ! $spendSnapshotIsFresh => 'stale',
            $spendCurrency === null || $revenueCurrency === null => 'currency_unknown',
            $spendCurrency !== $revenueCurrency => 'currency_mismatch',
            default => 'available',
        };

        if ($status !== 'available' || $marginPercent === null || $revenue === null || $spendAmount === null) {
            return [
                ...$base,
                'break_even_roas_status' => $status,
                'break_even_roas_status_label' => $this->breakEvenStatusLabel($status),
                'currency_code' => $status === 'currency_mismatch' || $status === 'currency_unknown' ? null : $revenueCurrency,
                'status' => $status,
                'status_label' => $this->statusLabel($status),
            ];
        }

        $grossProfit = $revenue * $marginPercent / 100;
        $mediaContribution = $grossProfit - $spendAmount;
        $breakEvenRoas = $marginPercent > 0 ? 100 / $marginPercent : null;

        if (! is_finite($grossProfit) || ! is_finite($mediaContribution)
            || ($breakEvenRoas !== null && ! is_finite($breakEvenRoas))) {
            return [
                ...$base,
                'break_even_roas_status' => 'invalid_calculation',
                'break_even_roas_status_label' => '目前無法計算',
                'status' => 'invalid_calculation',
                'status_label' => $this->statusLabel('invalid_calculation'),
            ];
        }

        $breakEvenStatus = $marginPercent === 0.0 ? 'zero_margin' : 'available';

        return [
            ...$base,
            'gross_profit_amount' => round($grossProfit, 4),
            'media_contribution_amount' => round($mediaContribution, 4),
            'break_even_roas' => $breakEvenRoas === null ? null : round($breakEvenRoas, 4),
            'break_even_roas_status' => $breakEvenStatus,
            'break_even_roas_status_label' => $this->breakEvenStatusLabel($breakEvenStatus),
            'currency_code' => $revenueCurrency,
            'status' => 'available',
            'status_label' => $this->statusLabel('available'),
        ];
    }

    private function finiteNumber(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    private function currencyCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $currencyCode = strtoupper(trim($value));

        return preg_match('/^[A-Z]{3}$/D', $currencyCode) === 1 ? $currencyCode : null;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'available' => '估算可用',
            'not_configured' => '尚未設定活動毛利率',
            'invalid_margin' => '毛利率資料無效',
            'missing_ga4' => '尚無 GA4 營收',
            'missing_revenue' => 'GA4 營收資料無效',
            'missing_spend' => '尚無可配對媒體花費',
            'stale' => 'GA4 或必要的媒體花費資料缺漏或超過 24 小時',
            'currency_unknown' => '幣別資訊不足',
            'currency_mismatch' => 'GA4 營收與媒體花費幣別不同',
            default => '估算資料無效，暫不顯示',
        };
    }

    private function breakEvenStatusLabel(string $status): string
    {
        return match ($status) {
            'available' => '依毛利率估算',
            'zero_margin' => '毛利率為 0%，無損益兩平 ROAS',
            default => $this->statusLabel($status),
        };
    }
}
