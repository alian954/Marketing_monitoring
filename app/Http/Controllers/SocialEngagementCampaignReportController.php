<?php

namespace App\Http\Controllers;

use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Services\SocialEngagementCampaignReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SocialEngagementCampaignReportController extends Controller
{
    public function show(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        SocialEngagementCampaignReport $campaignReport,
    ): View {
        $campaignName = is_string($socialEngagementTarget->ga4_campaign_name)
            && trim($socialEngagementTarget->ga4_campaign_name) !== ''
                ? trim($socialEngagementTarget->ga4_campaign_name)
                : $socialEngagementTarget->tag;
        $sourceName = is_string($socialEngagementTarget->ga4_source_name)
            && trim($socialEngagementTarget->ga4_source_name) !== ''
                ? trim($socialEngagementTarget->ga4_source_name)
                : null;
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

        return view('social-engagement-reports.show', [
            ...$campaignReport->build($socialEngagementTarget),
            'googleAnalyticsConnection' => $connection,
            'googleAnalyticsReport' => $analyticsReport,
            'googleAnalyticsCampaignName' => $campaignName,
            'googleAnalyticsSourceName' => $sourceName,
        ]);
    }
}
