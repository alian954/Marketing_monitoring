<?php

namespace App\Http\Controllers;

use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Services\CampaignMarketingEfficiencyReport;
use App\Services\CsvFormulaSanitizer;
use App\Services\GoogleAnalyticsCampaignTrendReport;
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
        CampaignMarketingEfficiencyReport $marketingEfficiencyReport,
    ): View {
        $campaignName = $socialEngagementTarget->googleAnalyticsCampaignName();
        $sourceName = $socialEngagementTarget->googleAnalyticsSourceName();
        $connection = $request->user()->googleAnalyticsConnection;
        $analyticsReport = $connection === null
            ? null
            : SocialEngagementTargetGoogleAnalyticsReport::query()
                ->where('google_analytics_connection_id', $connection->getKey())
                ->where('social_engagement_target_id', $socialEngagementTarget->getKey())
                ->where('campaign_name', $campaignName)
                ->when($sourceName !== null, fn (Builder $query): Builder => $query->where('source_name', $sourceName))
                ->when($sourceName === null, fn (Builder $query): Builder => $query->whereNull('source_name'))
                ->when($socialEngagementTarget->starts_on !== null, fn (Builder $query): Builder => $query->whereDate('starts_on', $socialEngagementTarget->starts_on))
                ->when($socialEngagementTarget->ends_on !== null, fn (Builder $query): Builder => $query->whereDate('ends_on', $socialEngagementTarget->ends_on))
                ->first();

        $campaignData = $campaignReport->build($socialEngagementTarget);

        return view('social-engagement-reports.show', [
            ...$campaignData,
            'googleAnalyticsConnection' => $connection,
            'googleAnalyticsReport' => $analyticsReport,
            'googleAnalyticsCampaignName' => $campaignName,
            'googleAnalyticsSourceName' => $sourceName,
            'googleAnalyticsTrend' => $connection !== null && $analyticsReport !== null
                ? $googleAnalyticsTrendReport->recentChanges(
                    $connection,
                    $socialEngagementTarget,
                    $campaignName,
                    $sourceName,
                )
                : [],
            'adSpendAnalysis' => $marketingEfficiencyReport->build(
                $socialEngagementTarget,
                $analyticsReport,
                $campaignData['report']['interaction_total'],
            ),
        ]);
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
}
