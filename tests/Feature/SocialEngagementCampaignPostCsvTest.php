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

class SocialEngagementCampaignPostCsvTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_post_export_streams_every_in_period_post_with_ga4_attribution_and_duplicate_protection(): void
    {
        $user = User::factory()->create();
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'encrypted-token',
        ]);
        $campaignDate = now()->toDateString();
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Spring launch',
            'social_platform' => 'instagram',
            'starts_on' => $campaignDate,
            'ends_on' => $campaignDate,
            'ga4_campaign_name' => 'spring-launch',
            'ga4_source_name' => 'instagram',
            'ga4_medium_name' => 'social',
        ]);

        for ($index = 0; $index < 251; $index++) {
            $utmContent = match ($index) {
                0, 250 => 'duplicate-creative',
                1 => 'creative-match',
                2 => null,
                default => 'creative-'.$index,
            };
            $this->createPost($target, $index, $utmContent, now(), $index === 1 ? '=2+2' : null);
        }

        $this->createPost($target, 251, 'outside-period', now()->subDays(2));
        $this->createPost($target, 252, 'unknown-date', null);
        $this->createPost($target, 253, 'different-platform', now(), null, 'facebook');

        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => 'spring-launch',
            'attribution_mode' => 'manual_utm',
            'campaign_id' => null,
            'source_name' => 'instagram',
            'medium_name' => 'social',
            'starts_on' => $campaignDate,
            'ends_on' => $campaignDate,
            'sessions' => 125,
            'engaged_sessions' => 90,
            'key_events' => '8.0000',
            'total_revenue' => '12345.6700',
            'currency_code' => 'TWD',
            'ad_content_report' => [
                'status' => 'complete',
                'currency_code' => 'TWD',
                'truncated' => false,
                'rows' => [[
                    'ad_content' => 'creative-match',
                    'sessions' => 125,
                    'engaged_sessions' => 90,
                    'engagement_rate' => '0.72',
                    'key_events' => '8.0000',
                    'total_revenue' => '12345.6700',
                ]],
            ],
            'ad_content_report_expires_at' => now()->addDay(),
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report.posts.export', $target))
            ->assertDownload('campaign-posts-'.$target->id.'.csv')
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $rows = $this->csvRows($response->streamedContent());

        $this->assertCount(252, $rows);
        $posts = array_map(fn (array $row): array => array_combine($rows[0], $row), array_slice($rows, 1));
        $byUtmContent = [];

        foreach ($posts as $post) {
            $byUtmContent[$post['utm_content']] = $post;
        }

        $this->assertSame('duplicate_utm_content', $byUtmContent['duplicate-creative']['ga4_attribution_status']);
        $this->assertSame('', $byUtmContent['duplicate-creative']['ga4_sessions']);
        $this->assertSame('matched', $byUtmContent['creative-match']['ga4_attribution_status']);
        $this->assertSame('125', $byUtmContent['creative-match']['ga4_sessions']);
        $this->assertSame('90', $byUtmContent['creative-match']['ga4_engaged_sessions']);
        $this->assertSame('0.72', $byUtmContent['creative-match']['ga4_engagement_rate']);
        $this->assertSame('8.0000', $byUtmContent['creative-match']['ga4_key_events']);
        $this->assertSame('12345.6700', $byUtmContent['creative-match']['ga4_total_revenue']);
        $this->assertSame("'=2+2", $byUtmContent['creative-match']['name']);
        $this->assertSame('not_configured', $byUtmContent['']['ga4_attribution_status']);
        $this->assertSame('8', $byUtmContent['creative-match']['public_interactions']);
    }

    public function test_campaign_post_export_requires_authentication(): void
    {
        $target = SocialEngagementTarget::factory()->create();

        $this->get(route('social-engagement-targets.report.posts.export', $target))
            ->assertRedirect(route('login'));
    }

    private function createPost(
        SocialEngagementTarget $target,
        int $index,
        ?string $utmContent,
        mixed $publishedAt,
        ?string $name = null,
        ?string $platform = null,
    ): Monitor {
        $url = match ($platform ?? $target->social_platform) {
            'facebook' => 'https://www.facebook.com/posts/campaign-'.$index,
            default => 'https://www.instagram.com/reel/campaign-'.$index.'/',
        };
        $monitor = Monitor::factory()->create([
            'name' => $name ?? 'Campaign post '.$index,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $target->tag,
            'utm_content' => $utmContent,
        ]);

        CheckRun::factory()->for($monitor)->create([
            'config_version' => $monitor->config_version,
            'status' => 'completed',
            'response_published_at' => $publishedAt,
            'response_public_engagement_metrics' => [
                'views' => 1000,
                'likes' => 5,
                'comments' => 3,
                'shares' => 1,
                'metric_sources' => [
                    'views' => 'public_page',
                    'likes' => 'public_page',
                    'comments' => 'public_page',
                    'shares' => 'public_page',
                ],
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
