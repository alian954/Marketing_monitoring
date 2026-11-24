<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\GoogleAnalyticsConnection;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialEngagementCampaignCreativePerformanceCsvTest extends TestCase
{
    use RefreshDatabase;

    public function test_creative_csv_exports_grouped_metrics_and_sanitizes_formula_values(): void
    {
        $user = User::factory()->create();
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'encrypted-token',
        ]);
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Creative reporting',
            'social_platform' => 'instagram',
        ]);
        $this->bindGoogleAnalyticsTargetToClient($user, $connection, $target);
        $this->createPost($target, 1, 'shared-creative');
        $this->createPost($target, 2, 'shared-creative');
        $this->createPost($target, 3, '=HYPERLINK("https://example.com")');
        $this->createPost($target, 4, null);

        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'property_id' => $connection->property_id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'attribution_mode' => 'manual_utm',
            'campaign_id' => null,
            'source_name' => null,
            'medium_name' => null,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'sessions' => 64,
            'engaged_sessions' => 52,
            'key_events' => '4.0000',
            'total_revenue' => '800.0000',
            'currency_code' => 'TWD',
            'ad_content_report' => [
                'status' => 'complete',
                'currency_code' => 'TWD',
                'truncated' => false,
                'rows' => [[
                    'ad_content' => 'shared-creative',
                    'sessions' => 41,
                    'engaged_sessions' => 33,
                    'engagement_rate' => '0.8049',
                    'key_events' => '3.0000',
                    'total_revenue' => '125.5000',
                ]],
            ],
            'ad_content_report_expires_at' => now()->addDay(),
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.creative-performance.export', $target))
            ->assertDownload('campaign-creative-performance-'.$target->id.'.csv')
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $rows = $this->csvRows($response->streamedContent());
        $creatives = array_map(
            static fn (array $row): array => array_combine($rows[0], $row),
            array_slice($rows, 1),
        );
        $byContent = [];

        foreach ($creatives as $creative) {
            $byContent[$creative['utm_content']] = $creative;
        }

        $this->assertCount(4, $rows);
        $this->assertSame('2', $byContent['shared-creative']['tracked_posts']);
        $this->assertSame('2', $byContent['shared-creative']['complete_read_posts']);
        $this->assertSame('16', $byContent['shared-creative']['public_interaction_total_from_complete_reads']);
        $this->assertSame('8.00', $byContent['shared-creative']['average_public_interactions_per_complete_post']);
        $this->assertSame('41', $byContent['shared-creative']['ga4_sessions']);
        $this->assertSame('3.0000', $byContent['shared-creative']['ga4_key_events']);
        $this->assertSame('125.5000', $byContent['shared-creative']['ga4_total_revenue']);
        $this->assertSame('7.8438', $byContent['shared-creative']['ga4_revenue_per_public_interaction_reference']);
        $this->assertSame('TWD', $byContent['shared-creative']['revenue_per_public_interaction_currency']);
        $this->assertSame('available', $byContent['shared-creative']['revenue_per_public_interaction_status']);
        $this->assertSame('\'=HYPERLINK("https://example.com")', $byContent['\'=HYPERLINK("https://example.com")']['utm_content']);
        $this->assertSame('not_configured', $byContent['']['ga4_attribution_status']);
    }

    public function test_creative_csv_requires_authentication(): void
    {
        $target = SocialEngagementTarget::factory()->create();

        $this->get(route('social-engagement-targets.creative-performance.export', $target))
            ->assertRedirect(route('login'));
    }

    private function createPost(SocialEngagementTarget $target, int $index, ?string $utmContent): Monitor
    {
        $url = 'https://www.instagram.com/reel/creative-export-'.$index.'/';
        $monitor = Monitor::factory()->create([
            'name' => 'Creative export post '.$index,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $target->tag,
            'utm_content' => $utmContent,
        ]);

        if ($target->user_id !== null && $target->client_id !== null) {
            $monitor->forceFill([
                'user_id' => $target->user_id,
                'client_id' => $target->client_id,
            ])->save();
        }

        CheckRun::factory()->for($monitor)->create([
            'config_version' => $monitor->config_version,
            'status' => 'completed',
            'response_published_at' => now(),
            'response_public_engagement_metrics' => [
                'views' => 400,
                'likes' => 5,
                'comments' => 3,
            ],
        ]);

        return $monitor;
    }

    /** @return list<list<string>> */
    private function csvRows(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));

        return array_map(static fn (string $line): array => str_getcsv($line), $lines ?: []);
    }
}
