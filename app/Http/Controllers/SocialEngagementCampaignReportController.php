<?php

namespace App\Http\Controllers;

use App\Models\GoogleAdsConnection;
use App\Models\GoogleAnalyticsConnection;
use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Services\CampaignMarketingEfficiencyReport;
use App\Services\CampaignMarketingReportCsv;
use App\Services\CsvFormulaSanitizer;
use App\Services\GoogleAnalyticsCampaignTrendReport;
use App\Services\PaidMediaCampaignTrendReport;
use App\Services\SocialEngagementCampaignReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SocialEngagementCampaignReportController extends Controller
{
    public function show(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        SocialEngagementCampaignReport $campaignReport,
        GoogleAnalyticsCampaignTrendReport $googleAnalyticsTrendReport,
        PaidMediaCampaignTrendReport $paidMediaTrendReport,
        CampaignMarketingEfficiencyReport $marketingEfficiencyReport,
    ): View {
        return view('social-engagement-reports.show', $this->reportData(
            $request,
            $socialEngagementTarget,
            $campaignReport,
            $googleAnalyticsTrendReport,
            $paidMediaTrendReport,
            $marketingEfficiencyReport,
        ));
    }

    public function exportCampaignSummary(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        SocialEngagementCampaignReport $campaignReport,
        GoogleAnalyticsCampaignTrendReport $googleAnalyticsTrendReport,
        PaidMediaCampaignTrendReport $paidMediaTrendReport,
        CampaignMarketingEfficiencyReport $marketingEfficiencyReport,
        CampaignMarketingReportCsv $campaignMarketingReportCsv,
    ): StreamedResponse {
        $data = $this->reportData(
            $request,
            $socialEngagementTarget,
            $campaignReport,
            $googleAnalyticsTrendReport,
            $paidMediaTrendReport,
            $marketingEfficiencyReport,
            false,
        );
        $csv = $campaignMarketingReportCsv->build($data);

        return response()->streamDownload(function () use ($csv): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $csv['headers'], ',', '"', '');
            fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $csv['fields']), ',', '"', '');
            fclose($stream);
        }, 'campaign-report-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{
     *     target: SocialEngagementTarget,
     *     report: array<string, mixed>,
     *     googleAnalyticsConnection: GoogleAnalyticsConnection|null,
     *     googleAnalyticsReport: SocialEngagementTargetGoogleAnalyticsReport|null,
     *     googleAnalyticsReportIsStale: bool,
     *     metaMarketingConnection: MetaMarketingConnection|null,
     *     metaMarketingReport: SocialEngagementTargetMetaMarketingReport|null,
     *     metaMarketingReportIsStale: bool,
     *     metaMarketingTrend: list<array<string, mixed>>,
     *     googleAdsConnection: GoogleAdsConnection|null,
     *     googleAdsReport: SocialEngagementTargetGoogleAdsReport|null,
     *     googleAdsCampaignOptions: list<array{campaign_id: string, campaign_name: string, status: string, channel_type: string}>|null,
     *     googleAdsReportIsStale: bool,
     *     googleAdsTrend: list<array<string, mixed>>,
     *     googleAnalyticsCampaignName: string,
     *     googleAnalyticsSourceName: string|null,
     *     googleAnalyticsMediumName: string|null,
     *     googleAnalyticsTrend: list<array<string, mixed>>,
     *     adSpendAnalysis: array<string, mixed>
     * }
     */
    private function reportData(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        SocialEngagementCampaignReport $campaignReport,
        GoogleAnalyticsCampaignTrendReport $googleAnalyticsTrendReport,
        PaidMediaCampaignTrendReport $paidMediaTrendReport,
        CampaignMarketingEfficiencyReport $marketingEfficiencyReport,
        bool $includeHistoricalDetails = true,
    ): array {
        $campaignName = $socialEngagementTarget->googleAnalyticsCampaignName();
        $sourceName = $socialEngagementTarget->googleAnalyticsSourceName();
        $mediumName = $socialEngagementTarget->googleAnalyticsMediumName();
        $googleAnalyticsConnection = $request->user()->googleAnalyticsConnection;
        $analyticsReport = $this->googleAnalyticsReportForTarget($socialEngagementTarget, $googleAnalyticsConnection);

        $metaMarketingConnection = $request->user()->metaMarketingConnection;
        $metaMarketingReport = $metaMarketingConnection === null
            || $metaMarketingConnection->ad_account_id === null
            || $socialEngagementTarget->meta_campaign_id === null
            || $socialEngagementTarget->starts_on === null
            || $socialEngagementTarget->ends_on === null
            ? null
            : SocialEngagementTargetMetaMarketingReport::query()
                ->where('meta_marketing_connection_id', $metaMarketingConnection->getKey())
                ->where('social_engagement_target_id', $socialEngagementTarget->getKey())
                ->where('ad_account_id', $metaMarketingConnection->ad_account_id)
                ->where('campaign_id', $socialEngagementTarget->meta_campaign_id)
                ->whereDate('starts_on', $socialEngagementTarget->starts_on)
                ->whereDate('ends_on', $socialEngagementTarget->ends_on)
                ->first();

        $googleAdsConnection = $request->user()->googleAdsConnection;
        $googleAdsReport = $googleAdsConnection === null
            || $googleAdsConnection->customer_id === null
            || $socialEngagementTarget->google_ads_campaign_id === null
            || $socialEngagementTarget->starts_on === null
            || $socialEngagementTarget->ends_on === null
            ? null
            : SocialEngagementTargetGoogleAdsReport::query()
                ->where('google_ads_connection_id', $googleAdsConnection->getKey())
                ->where('social_engagement_target_id', $socialEngagementTarget->getKey())
                ->where('customer_id', $googleAdsConnection->customer_id)
                ->where('campaign_id', $socialEngagementTarget->google_ads_campaign_id)
                ->whereDate('starts_on', $socialEngagementTarget->starts_on)
                ->whereDate('ends_on', $socialEngagementTarget->ends_on)
                ->first();

        $googleAdsCampaignOptions = session('google_ads_campaign_options');
        $googleAdsCampaignOptions = is_array($googleAdsCampaignOptions)
            && (string) ($googleAdsCampaignOptions['target_id'] ?? '') === (string) $socialEngagementTarget->getKey()
            && is_array($googleAdsCampaignOptions['campaigns'] ?? null)
            ? $googleAdsCampaignOptions['campaigns']
            : null;

        $campaignData = $campaignReport->build($socialEngagementTarget, $includeHistoricalDetails);
        $reportFreshnessCutoff = now()->subDay();

        return [
            ...$campaignData,
            'googleAnalyticsConnection' => $googleAnalyticsConnection,
            'googleAnalyticsReport' => $analyticsReport,
            'googleAnalyticsReportIsStale' => $analyticsReport !== null
                && $analyticsReport->synced_at->lessThanOrEqualTo($reportFreshnessCutoff),
            'metaMarketingConnection' => $metaMarketingConnection,
            'metaMarketingReport' => $metaMarketingReport,
            'metaMarketingReportIsStale' => $metaMarketingReport !== null
                && $metaMarketingReport->synced_at->lessThanOrEqualTo($reportFreshnessCutoff),
            'metaMarketingTrend' => $includeHistoricalDetails
                ? $paidMediaTrendReport->metaMarketingChanges($metaMarketingConnection, $socialEngagementTarget)
                : [],
            'googleAdsConnection' => $googleAdsConnection,
            'googleAdsReport' => $googleAdsReport,
            'googleAdsCampaignOptions' => $googleAdsCampaignOptions,
            'googleAdsReportIsStale' => $googleAdsReport !== null
                && $googleAdsReport->synced_at->lessThanOrEqualTo($reportFreshnessCutoff),
            'googleAdsTrend' => $includeHistoricalDetails
                ? $paidMediaTrendReport->googleAdsChanges($googleAdsConnection, $socialEngagementTarget)
                : [],
            'googleAnalyticsCampaignName' => $campaignName,
            'googleAnalyticsSourceName' => $sourceName,
            'googleAnalyticsMediumName' => $mediumName,
            'googleAnalyticsTrend' => $includeHistoricalDetails && $googleAnalyticsConnection !== null && $analyticsReport !== null
                ? $googleAnalyticsTrendReport->recentChanges(
                    $googleAnalyticsConnection,
                    $socialEngagementTarget,
                    $campaignName,
                    $sourceName,
                    $mediumName,
                )
                : [],
            'adSpendAnalysis' => $marketingEfficiencyReport->build(
                $socialEngagementTarget,
                $analyticsReport,
                $campaignData['report']['interaction_total'],
                $metaMarketingReport,
                $googleAdsReport,
            ),
        ];
    }

    public function exportGoogleAnalyticsChannelBreakdown(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
    ): StreamedResponse {
        $analyticsReport = $this->googleAnalyticsReportForTarget(
            $socialEngagementTarget,
            $request->user()->googleAnalyticsConnection,
        );
        $rows = $analyticsReport?->source_medium_breakdown;

        abort_if(! is_array($rows) || $rows === [], 404);

        return response()->streamDownload(function () use ($analyticsReport, $rows): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'campaign_name',
                'campaign_start',
                'campaign_end',
                'channel_group',
                'source',
                'medium',
                'sessions',
                'engaged_sessions',
                'key_events',
                'total_revenue',
                'breakdown_truncated',
                'currency_code',
                'synced_at_asia_taipei',
            ], ',', '"', '');

            foreach ($rows as $row) {
                $fields = [
                    $analyticsReport->campaign_name,
                    $analyticsReport->starts_on->toDateString(),
                    $analyticsReport->ends_on->toDateString(),
                    $row['channel_group'] ?? '',
                    $row['source_name'] ?? '',
                    $row['medium_name'] ?? '',
                    (string) ($row['sessions'] ?? ''),
                    (string) ($row['engaged_sessions'] ?? ''),
                    (string) ($row['key_events'] ?? ''),
                    (string) ($row['total_revenue'] ?? ''),
                    $analyticsReport->source_medium_breakdown_truncated ? 'true' : 'false',
                    $analyticsReport->currency_code ?? '',
                    $analyticsReport->synced_at?->timezone('Asia/Taipei')->format('Y-m-d H:i:s') ?? '',
                ];

                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $fields), ',', '"', '');
            }

            fclose($stream);
        }, 'ga4-channel-breakdown-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function googleAnalyticsReportForTarget(
        SocialEngagementTarget $target,
        ?GoogleAnalyticsConnection $connection,
    ): ?SocialEngagementTargetGoogleAnalyticsReport {
        if ($connection === null) {
            return null;
        }

        $sourceName = $target->googleAnalyticsSourceName();
        $mediumName = $target->googleAnalyticsMediumName();

        return SocialEngagementTargetGoogleAnalyticsReport::query()
            ->where('google_analytics_connection_id', $connection->getKey())
            ->where('social_engagement_target_id', $target->getKey())
            ->where('campaign_name', $target->googleAnalyticsCampaignName())
            ->when($sourceName !== null, fn (Builder $query): Builder => $query->where('source_name', $sourceName))
            ->when($sourceName === null, fn (Builder $query): Builder => $query->whereNull('source_name'))
            ->when($mediumName !== null, fn (Builder $query): Builder => $query->where('medium_name', $mediumName))
            ->when($mediumName === null, fn (Builder $query): Builder => $query->whereNull('medium_name'))
            ->when($target->starts_on !== null, fn (Builder $query): Builder => $query->whereDate('starts_on', $target->starts_on))
            ->when($target->ends_on !== null, fn (Builder $query): Builder => $query->whereDate('ends_on', $target->ends_on))
            ->first();
    }

    public function exportGoogleAnalyticsHistory(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        GoogleAnalyticsCampaignTrendReport $googleAnalyticsTrendReport,
    ): StreamedResponse {
        $connection = $request->user()->googleAnalyticsConnection;

        abort_if(
            $connection === null
                || ! is_string($connection->property_id)
                || $connection->property_id === ''
                || $socialEngagementTarget->starts_on === null
                || $socialEngagementTarget->ends_on === null,
            404,
        );

        $rows = $googleAnalyticsTrendReport->recentChanges(
            $connection,
            $socialEngagementTarget,
            $socialEngagementTarget->googleAnalyticsCampaignName(),
            $socialEngagementTarget->googleAnalyticsSourceName(),
            $socialEngagementTarget->googleAnalyticsMediumName(),
        );

        return response()->streamDownload(function () use ($rows): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'snapshot_date',
                'synced_at_asia_taipei',
                'sessions_cumulative',
                'sessions_change_since_previous',
                'engaged_sessions_cumulative',
                'engaged_sessions_change_since_previous',
                'key_events_cumulative',
                'key_events_change_since_previous',
                'total_revenue_cumulative',
                'total_revenue_change_since_previous',
                'currency_code',
            ], ',', '"', '');

            foreach ($rows as $row) {
                $fields = [
                    $row['snapshot_date']->toDateString(),
                    $row['synced_at']->timezone('Asia/Taipei')->format('Y-m-d H:i:s'),
                    (string) $row['sessions'],
                    $row['sessions_change'] === null ? '' : (string) $row['sessions_change'],
                    (string) $row['engaged_sessions'],
                    $row['engaged_sessions_change'] === null ? '' : (string) $row['engaged_sessions_change'],
                    $row['key_events'],
                    $row['key_events_change'] === null ? '' : number_format($row['key_events_change'], 4, '.', ''),
                    $row['total_revenue'],
                    $row['total_revenue_change'] === null ? '' : number_format($row['total_revenue_change'], 4, '.', ''),
                    $row['currency_code'] ?? '',
                ];

                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $fields), ',', '"', '');
            }

            fclose($stream);
        }, 'ga4-campaign-trend-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportPaidMediaHistory(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        PaidMediaCampaignTrendReport $paidMediaTrendReport,
        string $source,
    ): StreamedResponse {
        [$rows, $sourceLabel, $filePrefix] = match ($source) {
            'meta-marketing' => [
                $paidMediaTrendReport->metaMarketingChanges($request->user()->metaMarketingConnection, $socialEngagementTarget),
                'Meta Ads',
                'meta-marketing',
            ],
            'google-ads' => [
                $paidMediaTrendReport->googleAdsChanges($request->user()->googleAdsConnection, $socialEngagementTarget),
                'Google Ads',
                'google-ads',
            ],
            default => abort(404),
        };

        abort_if($rows === [], 404);

        return response()->streamDownload(function () use ($rows, $sourceLabel): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'source',
                'campaign_name',
                'synced_at_asia_taipei',
                'spend_cumulative',
                'spend_change_since_previous',
                'currency_code',
                'impressions_cumulative',
                'impressions_change_since_previous',
                'reach_cumulative',
                'reach_change_since_previous',
                'frequency_cumulative',
                'frequency_change_since_previous',
                'clicks_cumulative',
                'clicks_change_since_previous',
                'conversions_cumulative',
                'conversions_change_since_previous',
                'conversions_value_cumulative',
                'conversions_value_change_since_previous',
                'roas_value_cumulative',
                'roas',
                'roas_change_since_previous',
                'ctr_percent',
                'ctr_change_percentage_points_since_previous',
                'cost_per_click',
                'cost_per_click_change_since_previous',
                'cost_per_thousand_impressions',
                'cost_per_thousand_impressions_change_since_previous',
                'cost_per_conversion',
                'cost_per_conversion_change_since_previous',
                'conversion_rate_percent',
                'conversion_rate_change_percentage_points_since_previous',
                'actions',
                'action_values',
                'conversion_actions',
            ], ',', '"', '');

            foreach ($rows as $row) {
                $fields = [
                    $sourceLabel,
                    $row['campaign_name'],
                    $row['synced_at']->timezone('Asia/Taipei')->format('Y-m-d H:i:s'),
                    $row['spend_amount'],
                    $row['spend_change'] === null ? '' : number_format($row['spend_change'], 4, '.', ''),
                    $row['currency_code'],
                    (string) $row['impressions'],
                    $row['impressions_change'] === null ? '' : (string) $row['impressions_change'],
                    $row['reach'] === null ? '' : (string) $row['reach'],
                    $row['reach_change'] === null ? '' : (string) $row['reach_change'],
                    $row['frequency'] === null ? '' : number_format($row['frequency'], 4, '.', ''),
                    $row['frequency_change'] === null ? '' : number_format($row['frequency_change'], 4, '.', ''),
                    (string) $row['clicks'],
                    $row['clicks_change'] === null ? '' : (string) $row['clicks_change'],
                    $row['conversions'] ?? '',
                    $row['conversions_change'] === null ? '' : number_format($row['conversions_change'], 4, '.', ''),
                    $row['conversions_value'] ?? '',
                    $row['conversions_value_change'] === null ? '' : number_format($row['conversions_value_change'], 4, '.', ''),
                    $row['roas_value'] ?? '',
                    $row['roas'] === null ? '' : number_format($row['roas'], 2, '.', ''),
                    $row['roas_change'] === null ? '' : number_format($row['roas_change'], 2, '.', ''),
                    $row['ctr_percent'] === null ? '' : number_format($row['ctr_percent'], 2, '.', ''),
                    $row['ctr_change_percentage_points'] === null ? '' : number_format($row['ctr_change_percentage_points'], 2, '.', ''),
                    $row['cost_per_click'] === null ? '' : number_format($row['cost_per_click'], 4, '.', ''),
                    $row['cost_per_click_change'] === null ? '' : number_format($row['cost_per_click_change'], 4, '.', ''),
                    $row['cost_per_thousand_impressions'] === null ? '' : number_format($row['cost_per_thousand_impressions'], 4, '.', ''),
                    $row['cost_per_thousand_impressions_change'] === null ? '' : number_format($row['cost_per_thousand_impressions_change'], 4, '.', ''),
                    $row['cost_per_conversion'] === null ? '' : number_format($row['cost_per_conversion'], 4, '.', ''),
                    $row['cost_per_conversion_change'] === null ? '' : number_format($row['cost_per_conversion_change'], 4, '.', ''),
                    $row['conversion_rate_percent'] === null ? '' : number_format($row['conversion_rate_percent'], 2, '.', ''),
                    $row['conversion_rate_change_percentage_points'] === null ? '' : number_format($row['conversion_rate_change_percentage_points'], 2, '.', ''),
                    json_encode($row['actions'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($row['action_values'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($row['conversion_actions'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];

                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $fields), ',', '"', '');
            }

            fclose($stream);
        }, $filePrefix.'-campaign-trend-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
