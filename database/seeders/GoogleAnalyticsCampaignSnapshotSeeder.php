<?php

namespace Database\Seeders;

use App\Models\GoogleAnalyticsCampaignSnapshot;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

class GoogleAnalyticsCampaignSnapshotSeeder extends Seeder
{
    public function run(): void
    {
        SocialEngagementTargetGoogleAnalyticsReport::query()
            ->orderBy('id')
            ->chunkById(200, function (Collection $reports): void {
                foreach ($reports as $report) {
                    GoogleAnalyticsCampaignSnapshot::query()->updateOrCreate(
                        [
                            'google_analytics_connection_id' => $report->google_analytics_connection_id,
                            'social_engagement_target_id' => $report->social_engagement_target_id,
                            'snapshot_date' => $report->synced_at->timezone('Asia/Taipei')->toDateString(),
                        ],
                        [
                            'campaign_name' => $report->campaign_name,
                            'attribution_mode' => $report->attribution_mode,
                            'campaign_id' => $report->campaign_id,
                            'source_name' => $report->source_name,
                            'medium_name' => $report->medium_name,
                            'starts_on' => $report->starts_on->toDateString(),
                            'ends_on' => $report->ends_on->toDateString(),
                            'sessions' => $report->sessions,
                            'engaged_sessions' => $report->engaged_sessions,
                            'key_events' => $report->key_events,
                            'total_revenue' => $report->total_revenue,
                            'currency_code' => $report->currency_code,
                            'source_medium_breakdown' => $report->source_medium_breakdown,
                            'source_medium_breakdown_truncated' => $report->source_medium_breakdown_truncated,
                            'synced_at' => $report->synced_at,
                        ],
                    );
                }
            });
    }
}
