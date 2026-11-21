<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DashboardSocialPlatformSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_summarizes_social_monitor_health_by_platform_and_links_to_platform_lists(): void
    {
        $this->travelTo('2026-11-21 12:00:00');
        Config::set('monitoring.stale_grace_seconds', 0);

        $facebookHealthyMonitor = $this->createSocialMonitor('facebook', 'FBHEALTHY1', 'Facebook healthy');
        $facebookHealthyMonitor->forceFill(['last_checked_at' => now()])->save();
        CheckRun::factory()->for($facebookHealthyMonitor)->create([
            'config_version' => $facebookHealthyMonitor->config_version,
            'normal_status' => 200,
            'scheduled_at' => now(),
        ]);

        $facebookIssueMonitor = $this->createSocialMonitor('facebook', 'FBISSUE001', 'Facebook issue');
        $facebookIssueMonitor->forceFill(['last_checked_at' => now()])->save();
        CheckRun::factory()->for($facebookIssueMonitor)->create([
            'config_version' => $facebookIssueMonitor->config_version,
            'normal_status' => 503,
            'error_type' => 'normal_http_status',
            'scheduled_at' => now(),
        ]);

        $instagramWarningMonitor = $this->createSocialMonitor('instagram', 'IGWARNING1', 'Instagram warning');
        $instagramWarningMonitor->forceFill(['last_checked_at' => now()])->save();
        CheckRun::factory()->for($instagramWarningMonitor)->create([
            'config_version' => $instagramWarningMonitor->config_version,
            'normal_status' => 200,
            'adsbot_status' => 503,
            'adsbot_error_type' => 'adsbot_http_status',
            'scheduled_at' => now(),
        ]);

        $threadsStaleMonitor = $this->createSocialMonitor('threads', 'THREADSTALE1', 'Threads stale');
        $threadsStaleMonitor->forceFill(['last_checked_at' => now()->subHours(2)])->save();

        $this->createSocialMonitor('threads', 'THREADPAUSE1', 'Threads paused', ['enabled' => false]);
        $this->createSocialMonitor('threads', 'THREADPEND01', 'Threads pending');
        $threadsShareUrl = 'https://www.threads.com/share/BAGYFgPihu';
        $threadsShareMonitor = Monitor::factory()->create([
            'name' => 'Threads share link',
            'url' => $threadsShareUrl,
            'url_hash' => Monitor::fingerprintUrl($threadsShareUrl),
            'expected_text' => 'Original post text',
        ]);
        Monitor::factory()->create([
            'name' => 'Website excluded from platform totals',
            'url' => 'https://example.test/status',
            'url_hash' => Monitor::fingerprintUrl('https://example.test/status'),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('社群平台狀態')
            ->assertSee('Facebook、Instagram 與 Threads')
            ->assertSee('需留意')
            ->assertSee('待檢查')
            ->assertSee('role="region" aria-labelledby="social-platform-summary-title" tabindex="0"', false)
            ->assertSee('<caption class="sr-only">Facebook、Instagram 與 Threads 公開貼文健康狀態統計</caption>', false)
            ->assertSee('<th scope="col">平台</th>', false)
            ->assertSee(route('monitors.index', ['platform' => 'facebook']), false)
            ->assertSee(route('monitors.index', ['platform' => 'instagram']), false)
            ->assertSee(route('monitors.index', ['platform' => 'threads']), false);

        $platformCounts = $response->viewData('platformCounts');

        $this->assertSame([
            'label' => 'Facebook',
            'total' => 2,
            'states' => ['healthy' => 1, 'issue' => 1, 'warning' => 0, 'stale' => 0, 'paused' => 0, 'pending' => 0],
        ], $platformCounts['facebook']);
        $this->assertSame([
            'label' => 'Instagram',
            'total' => 1,
            'states' => ['healthy' => 0, 'issue' => 0, 'warning' => 1, 'stale' => 0, 'paused' => 0, 'pending' => 0],
        ], $platformCounts['instagram']);
        $this->assertSame([
            'label' => 'Threads',
            'total' => 4,
            'states' => ['healthy' => 0, 'issue' => 0, 'warning' => 0, 'stale' => 1, 'paused' => 1, 'pending' => 2],
        ], $platformCounts['threads']);
        $this->assertDatabaseHas('monitors', [
            'id' => $threadsShareMonitor->id,
            'social_platform' => 'threads',
        ]);
        $this->assertSame(8, $response->viewData('monitorCount'));
    }

    public function test_dashboard_shows_an_empty_state_when_no_social_posts_are_monitored(): void
    {
        Monitor::factory()->create([
            'name' => 'Website only',
            'url' => 'https://example.test/website-only',
            'url_hash' => Monitor::fingerprintUrl('https://example.test/website-only'),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('尚無社群貼文監測項目')
            ->assertDontSee('Facebook、Instagram 與 Threads 公開貼文健康狀態統計');

        $this->assertSame(0, collect($response->viewData('platformCounts'))->sum('total'));
    }

    /** @param array<string, mixed> $attributes */
    private function createSocialMonitor(string $platform, string $postId, string $name, array $attributes = []): Monitor
    {
        $url = match ($platform) {
            'facebook' => 'https://www.facebook.com/brand/posts/'.$postId,
            'instagram' => 'https://www.instagram.com/p/'.$postId.'/',
            'threads' => 'https://www.threads.com/@brand/post/'.$postId,
        };

        return Monitor::factory()->create([
            ...$attributes,
            'name' => $name,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
    }
}
