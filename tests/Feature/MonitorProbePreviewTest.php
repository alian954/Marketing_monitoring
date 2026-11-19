<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\User;
use App\Services\Probe\HostResolver;
use App\Services\Probe\ProbeClient;
use App\Services\Probe\ProbeResult;
use App\Services\Probe\UrlSafetyPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorProbePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new class implements HostResolver
        {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };

        $this->app->instance(UrlSafetyPolicy::class, new UrlSafetyPolicy($resolver));
    }

    public function test_create_form_exposes_a_preview_button(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('monitors.create'))
            ->assertOk()
            ->assertSee('預檢網址')
            ->assertSee('formaction="'.route('monitors.probe-preview').'"', false)
            ->assertSee('完整原始回應不會保存');
    }

    public function test_probe_preview_requires_authentication(): void
    {
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldNotReceive('inspect');

        $this->post(route('monitors.probe-preview'), $this->socialPost('https://www.instagram.com/p/ABC123/'))
            ->assertRedirect(route('login'));
    }

    public function test_social_post_preview_reports_a_match_without_saving_monitor_or_response_body(): void
    {
        $url = 'https://www.instagram.com/p/ABC123/';
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->with($url, [], 'MarketingMonitor/1.0 (+authorized landing page check)', 'Campaign headline', 10, 'present')
            ->andReturn(new ProbeResult(
                200,
                245,
                $url,
                [],
                ['93.184.216.34'],
                null,
                null,
                true,
                null,
                responseTitle: 'Campaign <script>alert(1)</script>',
                responseDescription: '<img src=x onerror=alert(2)>',
                responseImageReferenceHash: str_repeat('d', 64),
                responseOpenGraphType: '<script>alert(3)</script>',
                responseCanonicalReferenceHash: str_repeat('e', 64),
                responsePublishedAt: new \DateTimeImmutable('2026-11-19T01:30:00+00:00'),
                responseModifiedAt: new \DateTimeImmutable('2026-11-20T12:45:00+00:00'),
            ));

        $response = $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->followingRedirects()
            ->post(route('monitors.probe-preview'), $this->socialPost($url));

        $response->assertOk()
            ->assertSee('預檢通過')
            ->assertSee('已找到全部指定片段')
            ->assertSee('HTTP 狀態與文字條件均符合目前設定。')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('&lt;img src=x onerror=alert(2)&gt;', false)
            ->assertSee('分享預覽圖片參照：已偵測')
            ->assertSee('Open Graph 類型：&lt;script&gt;alert(3)&lt;/script&gt;', false)
            ->assertSee('標準網址參照：已偵測')
            ->assertSee('分享發佈時間（UTC）：2026-11-19T01:30:00+00:00')
            ->assertSee('分享修改時間（UTC）：2026-11-20T12:45:00+00:00')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertDontSee('<script>alert(3)</script>', false)
            ->assertDontSee(str_repeat('d', 64))
            ->assertDontSee(str_repeat('e', 64))
            ->assertDontSee('<article>Campaign headline</article>', false);
        $this->assertDatabaseCount('monitors', 0);
        $this->assertDatabaseCount('check_runs', 0);
    }

    public function test_social_post_preview_accepts_any_expected_text_match(): void
    {
        $url = 'https://www.threads.com/@creator/post/ANY123';
        $expectedText = "Campaign headline\nSpring sale";
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->with($url, [], 'MarketingMonitor/1.0 (+authorized landing page check)', $expectedText, 10, 'present_any')
            ->andReturn(new ProbeResult(200, 60, $url, [], ['93.184.216.34'], null, null, true, null, [], null, null, [true, false]));

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->followingRedirects()
            ->post(route('monitors.probe-preview'), [
                ...$this->socialPost($url, $expectedText),
                'expected_text_mode' => 'present_any',
            ])
            ->assertOk()
            ->assertSee('預檢通過')
            ->assertSee('已找到至少一個指定片段')
            ->assertSee('Campaign headline')
            ->assertSee('原始回應找到此片段')
            ->assertSee('Spring sale')
            ->assertSee('原始回應未找到此片段');

        $this->assertDatabaseCount('monitors', 0);
        $this->assertDatabaseCount('check_runs', 0);
    }

    public function test_website_preview_can_check_http_status_without_a_content_condition(): void
    {
        $url = 'https://example.test/health';
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->with($url, [], 'MarketingMonitor/1.0 (+authorized landing page check)', null, 10, 'present')
            ->andReturn(new ProbeResult(204, 35, $url, [], ['93.184.216.34'], null, null, null, null));
        $data = $this->socialPost($url, '');

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->followingRedirects()
            ->post(route('monitors.probe-preview'), $data)
            ->assertOk()
            ->assertSee('預檢通過')
            ->assertSee('HTTP 狀態與文字條件均符合目前設定。')
            ->assertSee('分享標題：未提供')
            ->assertSee('分享摘要：未提供')
            ->assertSee('分享預覽圖片參照：未提供')
            ->assertSee('Open Graph 類型：未提供')
            ->assertSee('標準網址參照：未提供');

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_social_post_preview_reports_missing_text_without_creating_an_incident(): void
    {
        $url = 'https://www.facebook.com/page/posts/123/';
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->andReturn(new ProbeResult(
                200,
                80,
                $url,
                [],
                ['93.184.216.34'],
                null,
                null,
                false,
                null,
            ));

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->followingRedirects()
            ->post(route('monitors.probe-preview'), $this->socialPost($url))
            ->assertOk()
            ->assertSee('預檢未通過')
            ->assertSee('原始 HTTP 回應至少缺少一個指定片段。');

        $this->assertDatabaseCount('monitors', 0);
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_probe_preview_skips_text_comparison_for_an_unaccepted_http_status(): void
    {
        $url = 'https://www.facebook.com/page/posts/403/';
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->andReturn(new ProbeResult(
                403,
                80,
                $url,
                [],
                ['93.184.216.34'],
                null,
                null,
                false,
                null,
                [],
                null,
                null,
                [false],
            ));

        $response = $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->post(route('monitors.probe-preview'), [
                ...$this->socialPost($url),
                'expected_response_header' => 'X-Post-State: published',
            ])
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHas('probe_preview.expected_text_checked', false)
            ->assertSessionHas('probe_preview.expected_text_matches', [])
            ->assertSessionHas('probe_preview.expected_response_header_checked', false)
            ->assertSessionHas('probe_preview.message', 'HTTP 403 不在此監測設定接受的狀態碼範圍內。');

        $preview = $response->getSession()->get('probe_preview');
        $this->assertNull($preview['expected_text_state']);
        $this->assertNull($preview['expected_response_header_found']);
        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_probe_preview_checks_content_when_a_nonstandard_status_is_accepted(): void
    {
        $url = 'https://www.instagram.com/p/ABC123/';
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->andReturn(new ProbeResult(
                403,
                80,
                $url,
                [],
                ['93.184.216.34'],
                null,
                null,
                true,
                null,
                [],
                null,
                null,
                [true],
            ));

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->followingRedirects()
            ->post(route('monitors.probe-preview'), [
                ...$this->socialPost($url),
                'accepted_status_codes' => '403',
            ])
            ->assertOk()
            ->assertSee('預檢通過')
            ->assertSee('已找到全部指定片段')
            ->assertSee('HTTP 狀態與文字條件均符合目前設定。');

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_preview_applies_the_absent_condition_to_any_forbidden_line(): void
    {
        $url = 'https://example.test/landing';
        $expectedText = "Internal draft\nEmbargoed";
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->with($url, [], 'MarketingMonitor/1.0 (+authorized landing page check)', $expectedText, 10, 'absent')
            ->andReturn(new ProbeResult(200, 85, $url, [], ['93.184.216.34'], null, null, true, null));

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->followingRedirects()
            ->post(route('monitors.probe-preview'), [
                ...$this->socialPost($url, $expectedText),
                'expected_text_mode' => 'absent',
            ])
            ->assertOk()
            ->assertSee('預檢未通過')
            ->assertSee('找到不得出現的文字')
            ->assertSee('包含至少一個不得出現的片段');

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_social_post_preview_marks_an_oversized_response_as_indeterminate(): void
    {
        $url = 'https://www.threads.com/@creator/post/ABC123';
        $expectedText = "Campaign headline\nSpring sale";
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->andReturn(new ProbeResult(
                200,
                100,
                $url,
                [],
                ['93.184.216.34'],
                'body_too_large',
                '回應超過讀取上限。',
                null,
                null,
                [],
                null,
                null,
                [true, null],
            ));

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->followingRedirects()
            ->post(route('monitors.probe-preview'), $this->socialPost($url, $expectedText))
            ->assertOk()
            ->assertSee('無法完整判定')
            ->assertSee('回應超過讀取上限，指定文字比對尚未完成。')
            ->assertSee('文字比對：無法判定')
            ->assertSee('Campaign headline')
            ->assertSee('原始回應找到此片段')
            ->assertSee('Spring sale')
            ->assertSee('無法判定');

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_existing_monitor_preview_reuses_its_saved_url_without_changing_configuration(): void
    {
        $url = 'https://www.instagram.com/p/ABC123/';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'expected_text' => 'Campaign headline',
        ]);
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->with($url, [], 'MarketingMonitor/1.0 (+authorized landing page check)', 'Campaign headline', 10, 'present')
            ->andReturn(new ProbeResult(200, 30, $url, [], ['93.184.216.34'], null, null, true, null));

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.edit', $monitor))
            ->followingRedirects()
            ->put(route('monitors.probe-preview.update', $monitor), $this->socialPost('', 'Campaign headline'))
            ->assertOk()
            ->assertSee('預檢通過');

        $this->assertSame($url, $monitor->fresh()->url);
        $this->assertSame(1, $monitor->fresh()->config_version);
        $this->assertDatabaseCount('check_runs', 0);
    }

    public function test_private_probe_target_is_rejected_before_a_request_is_sent(): void
    {
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldNotReceive('inspect');

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->post(route('monitors.probe-preview'), $this->socialPost('http://127.0.0.1/admin'))
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors('url');

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_probe_preview_is_rate_limited_for_each_authenticated_user(): void
    {
        $url = 'https://www.instagram.com/p/ABC123/';
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')
            ->times(5)
            ->andReturn(new ProbeResult(200, 30, $url, [], ['93.184.216.34'], null, null, true, null));
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($user)
                ->from(route('monitors.create'))
                ->post(route('monitors.probe-preview'), $this->socialPost($url))
                ->assertRedirect(route('monitors.create'));
        }

        $this->actingAs($user)
            ->post(route('monitors.probe-preview'), $this->socialPost($url))
            ->assertTooManyRequests();
    }

    /** @return array<string, mixed> */
    private function socialPost(string $url, string $expectedText = 'Campaign headline'): array
    {
        return [
            'name' => 'Social post preflight',
            'url' => $url,
            'expected_text' => $expectedText,
            'expected_text_mode' => 'present',
            'timeout_seconds' => 10,
            'confirmation_delay_seconds' => 60,
            'interval_seconds' => 300,
            'accepted_status_codes' => '',
            'redirect_hosts' => '',
        ];
    }
}
