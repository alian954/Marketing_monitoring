<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Jobs\SendNotificationOutbox;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\IncidentManager;
use App\Services\NotificationDispatcher;
use App\Services\Probe\HopResponse;
use App\Services\Probe\HostResolver;
use App\Services\Probe\ProbeClient;
use App\Services\Probe\ProbeResult;
use App\Services\Probe\ProbeTransport;
use App\Services\Probe\SecureProbeClient;
use App\Services\Probe\UrlSafetyPolicy;
use App\Services\RunScheduler;
use App\Services\UrlMasker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SocialPostMonitorTest extends TestCase
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

    public function test_social_post_requires_expected_text(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->post(route('monitors.store'), $this->socialPost('https://www.instagram.com/p/ABC123/', "\n   \n"))
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors('expected_text');

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_direct_facebook_post_id_requires_expected_text(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->post(route('monitors.store'), $this->socialPost('https://www.facebook.com/10153449196353553'))
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors('expected_text');

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_social_post_requires_text_to_be_present(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->post(route('monitors.store'), $this->socialPost('https://threads.com/@creator/post/ABC123', 'Expected phrase', 'absent'))
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors('expected_text_mode');

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_social_post_accepts_the_any_expected_text_condition(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->post(route('monitors.store'), $this->socialPost(
                'https://www.instagram.com/p/ANY123/',
                "Campaign headline\nSpring sale",
                'present_any',
            ));

        $monitor = Monitor::query()->sole();

        $response->assertRedirect(route('monitors.show', $monitor));
        $this->assertSame('present_any', $monitor->expected_text_mode);
    }

    public function test_editing_a_social_post_cannot_remove_its_expected_text(): void
    {
        $url = 'https://www.instagram.com/p/ABC123/';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'expected_text' => 'Existing phrase',
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.edit', $monitor))
            ->put(route('monitors.update', $monitor), $this->socialPost($url))
            ->assertRedirect(route('monitors.edit', $monitor))
            ->assertSessionHasErrors('expected_text');

        $this->assertSame('Existing phrase', $monitor->fresh()->expected_text);
    }

    public function test_social_post_can_be_monitored_with_expected_text_in_the_raw_response(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->post(route('monitors.store'), $this->socialPost('https://www.threads.com/@creator/post/ABC123', 'Campaign headline'));

        $monitor = Monitor::query()->sole();

        $response->assertRedirect(route('monitors.show', $monitor));
        $this->assertSame('Campaign headline', $monitor->expected_text);
        $this->assertSame('present', $monitor->expected_text_mode);
        $this->assertSame('Threads', $monitor->socialPlatform());
        $this->assertDatabaseHas('monitors', [
            'id' => $monitor->id,
            'social_platform' => 'threads',
        ]);
    }

    public function test_facebook_group_permalink_can_be_monitored_as_a_social_post(): void
    {
        $url = 'https://www.facebook.com/groups/marketing/permalink/1234567890/';
        $response = $this->actingAs(User::factory()->create())
            ->post(route('monitors.store'), $this->socialPost($url, 'Launch headline'));

        $monitor = Monitor::query()->sole();

        $response->assertRedirect(route('monitors.show', $monitor));
        $this->assertSame('Launch headline', $monitor->expected_text);
        $this->assertSame('Facebook', $monitor->socialPlatform());
        $this->assertDatabaseHas('monitors', [
            'id' => $monitor->id,
            'social_platform' => 'facebook',
        ]);
    }

    public function test_social_post_stores_trimmed_and_deduplicated_lines_as_expected_text_terms(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->post(route('monitors.store'), $this->socialPost(
                'https://www.instagram.com/p/MULTI123/',
                "\n Campaign headline \n\nSpring sale\nCampaign headline\n",
            ));

        $monitor = Monitor::query()->sole();

        $response->assertRedirect(route('monitors.show', $monitor));
        $this->assertSame("Campaign headline\nSpring sale", $monitor->expected_text);
    }

    public function test_social_post_form_exposes_an_accessible_multiline_text_condition_field(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('monitors.create'))
            ->assertOk()
            ->assertSee('<textarea', false)
            ->assertSee('id="expected_text"', false)
            ->assertSee('maxlength="500"', false)
            ->assertSee('aria-describedby="expected-text-help"', false)
            ->assertSee('每行輸入一個文字片段')
            ->assertSee('全部片段都需包含')
            ->assertSee('至少包含一個片段')
            ->assertSee('option value="present_any"', false);
    }

    public function test_editing_a_social_post_updates_its_stored_platform(): void
    {
        $oldUrl = 'https://www.instagram.com/p/ABC123/';
        $monitor = Monitor::factory()->create([
            'url' => $oldUrl,
            'url_hash' => Monitor::fingerprintUrl($oldUrl),
            'expected_text' => 'Campaign headline',
        ]);
        $newUrl = 'https://www.threads.net/@creator/post/NEW123';

        $this->actingAs(User::factory()->create())
            ->put(route('monitors.update', $monitor), $this->socialPost($newUrl, 'Campaign headline'))
            ->assertRedirect(route('monitors.show', $monitor));

        $this->assertDatabaseHas('monitors', [
            'id' => $monitor->id,
            'social_platform' => 'threads',
        ]);
    }

    public function test_monitor_list_shows_the_latest_text_match_state_without_exposing_the_phrase(): void
    {
        $createMonitor = static function (string $name, string $url, ?string $expectedText): Monitor {
            return Monitor::factory()->create([
                'name' => $name,
                'url' => $url,
                'url_hash' => Monitor::fingerprintUrl($url),
                'expected_text' => $expectedText,
            ]);
        };
        $foundMonitor = $createMonitor('Published post', 'https://www.instagram.com/p/FOUND/', 'Private launch phrase');
        CheckRun::factory()->for($foundMonitor)->create([
            'expected_text_found' => false,
            'scheduled_at' => now()->subMinute(),
        ]);
        CheckRun::factory()->for($foundMonitor)->create(['expected_text_found' => true]);
        $missingMonitor = $createMonitor('Missing post', 'https://www.facebook.com/page/posts/MISSING/', 'Private missing phrase');
        CheckRun::factory()->for($missingMonitor)->create(['expected_text_found' => false]);
        $uncheckedMonitor = $createMonitor('Unverified post', 'https://www.threads.com/@creator/post/UNCHECKED', 'Private unchecked phrase');
        CheckRun::factory()->for($uncheckedMonitor)->create(['expected_text_found' => null]);
        $createMonitor('New post', 'https://www.instagram.com/p/NEW/', 'Private new phrase');
        $standardMonitor = $createMonitor('Website monitor', 'https://example.com/landing', null);
        CheckRun::factory()->for($standardMonitor)->create(['expected_text_found' => null]);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.index'))
            ->assertOk()
            ->assertSee('文字比對：')
            ->assertSee('已找到全部片段')
            ->assertSee('缺少至少一個片段')
            ->assertSee('尚未比對')
            ->assertSee('尚未檢查')
            ->assertDontSee('Private launch phrase')
            ->assertDontSee('Private missing phrase')
            ->assertDontSee('Private unchecked phrase')
            ->assertDontSee('Private new phrase');
    }

    public function test_standard_monitor_does_not_show_a_text_match_summary(): void
    {
        $url = 'https://example.com/without-text-condition';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
        CheckRun::factory()->for($monitor)->create(['expected_text_found' => null]);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.index'))
            ->assertOk()
            ->assertSee($monitor->name)
            ->assertDontSee('文字比對：');
    }

    public function test_social_post_url_detection_accepts_post_routes_and_direct_post_ids_but_not_profile_pages(): void
    {
        $this->assertSame('Facebook', Monitor::socialPlatformForUrl('https://m.facebook.com/post/123'));
        $this->assertSame('Facebook', Monitor::socialPlatformForUrl('https://www.facebook.com/10153449196353553'));
        $this->assertSame('Facebook', Monitor::socialPlatformForUrl('https://www.facebook.com/page/posts/123'));
        $this->assertSame('Facebook', Monitor::socialPlatformForUrl('https://www.facebook.com/groups/marketing/permalink/123'));
        $this->assertSame('Facebook', Monitor::socialPlatformForUrl('https://www.facebook.com/story.php?story_fbid=123'));
        $this->assertSame('Facebook', Monitor::socialPlatformForUrl('https://www.facebook.com/share/r/abc123/'));
        $this->assertSame('Instagram', Monitor::socialPlatformForUrl('https://www.instagram.com/p/123'));
        $this->assertSame('Instagram', Monitor::socialPlatformForUrl('https://www.instagram.com/stories/creator/123'));
        $this->assertSame('Threads', Monitor::socialPlatformForUrl('https://threads.net/@creator/post/123'));
        $this->assertSame('Facebook', Monitor::socialPlatformForUrl('https://fb.watch/ABC123/'));
        $this->assertNull(Monitor::socialPlatformForUrl('https://www.facebook.com/creator'));
        $this->assertNull(Monitor::socialPlatformForUrl('https://www.instagram.com/creator/'));
        $this->assertNull(Monitor::socialPlatformForUrl('https://www.threads.com/@creator'));
        $this->assertNull(Monitor::socialPlatformForUrl('https://instagram.com.attacker.test/p/123'));
    }

    public function test_social_profile_url_needs_no_post_text_and_keeps_adsbot_check(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $url = 'https://www.instagram.com/creator/';
        $response = $this->actingAs(User::factory()->create())
            ->post(route('monitors.store'), $this->socialPost($url));
        $monitor = Monitor::query()->sole();
        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');

        $this->assertNotNull($run);
        $response->assertRedirect(route('monitors.show', $monitor));
        $this->assertNull($monitor->expected_text);
        $this->assertNull($monitor->socialPlatform());

        $normal = new ProbeResult(200, 42, $url, [], ['93.184.216.34'], null, null, null, null);
        $adsbot = new ProbeResult(200, 38, $url, [], ['93.184.216.34'], null, null, null, null);
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->with($url, [], 'MarketingMonitor/1.0 (+authorized landing page check)', null, 10, 'present')
            ->andReturn($normal);
        $probe->shouldReceive('inspect')
            ->once()
            ->with($url, [], 'AdsBot-Google (+http://www.google.com/adsbot.html)', null, 10)
            ->andReturn($adsbot);

        (new RunMonitorCheck($run->id))->handle($probe, app(IncidentManager::class), app(RunScheduler::class));

        $this->assertDatabaseHas('check_runs', [
            'id' => $run->id,
            'status' => 'completed',
            'normal_status' => 200,
            'adsbot_status' => 200,
        ]);
    }

    public function test_social_post_check_uses_one_http_request_and_marks_adsbot_as_skipped(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $url = 'https://www.facebook.com/story.php?story_fbid=123';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'expected_text' => 'Campaign headline',
        ]);
        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);
        $normal = new ProbeResult(200, 42, $url, [], ['93.184.216.34'], null, null, true, null);
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->with($url, [], 'MarketingMonitor/1.0 (+authorized landing page check)', 'Campaign headline', 10, 'present')
            ->andReturn($normal);

        (new RunMonitorCheck($run->id))->handle($probe, app(IncidentManager::class), app(RunScheduler::class));

        $this->assertDatabaseHas('check_runs', [
            'id' => $run->id,
            'status' => 'completed',
            'normal_status' => 200,
            'adsbot_status' => null,
            'adsbot_error_type' => null,
            'elapsed_ms' => 42,
        ]);
        $this->assertDatabaseMissing('incidents', [
            'monitor_id' => $monitor->id,
            'rule_key' => 'adsbot_difference',
        ]);
        Queue::assertPushed(RunMonitorCheck::class, 1);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('略過（社群監測）')
            ->assertSee('不額外模擬 AdsBot');
    }

    public function test_large_social_post_response_with_split_markup_confirms_expected_text_from_its_bounded_prefix(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $url = 'https://www.instagram.com/p/ABC123/';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'expected_text' => 'Campaign headline',
        ]);
        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);
        $transport = new class implements ProbeTransport
        {
            public int $calls = 0;

            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                $this->calls++;

                return new HopResponse(200, null, '<article>Campaign <strong>headline</strong>', 40, 'body_too_large', '回應超過讀取上限。', null);
            }
        };
        $probe = new SecureProbeClient(app(UrlSafetyPolicy::class), $transport, new UrlMasker);

        (new RunMonitorCheck($run->id))->handle($probe, app(IncidentManager::class), app(RunScheduler::class));

        $this->assertSame(1, $transport->calls);
        $this->assertDatabaseHas('check_runs', [
            'id' => $run->id,
            'status' => 'completed',
            'normal_status' => 200,
            'error_type' => null,
            'expected_text_found' => 1,
        ]);
        $this->assertDatabaseMissing('incidents', ['monitor_id' => $monitor->id, 'rule_key' => 'content']);
    }

    public function test_large_social_post_response_without_a_match_opens_an_incomplete_check_warning(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $url = 'https://www.instagram.com/p/ABC123/';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'expected_text' => 'Campaign headline',
        ]);
        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);
        $transport = new class implements ProbeTransport
        {
            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                return new HopResponse(200, null, '<article>Other content</article>', 40, 'body_too_large', '回應超過讀取上限。', null);
            }
        };
        $probe = new SecureProbeClient(app(UrlSafetyPolicy::class), $transport, new UrlMasker);

        (new RunMonitorCheck($run->id))->handle($probe, app(IncidentManager::class), app(RunScheduler::class));

        $this->assertDatabaseHas('check_runs', [
            'id' => $run->id,
            'status' => 'completed',
            'normal_status' => 200,
            'error_type' => 'body_too_large',
            'expected_text_found' => null,
        ]);
        $this->assertDatabaseHas('incidents', [
            'monitor_id' => $monitor->id,
            'rule_key' => 'probe_incomplete',
            'severity' => 'warning',
            'state' => Incident::STATE_SUSPECT,
        ]);
        $this->assertDatabaseMissing('incidents', [
            'monitor_id' => $monitor->id,
            'rule_key' => 'http',
        ]);
        $this->assertDatabaseMissing('incidents', [
            'monitor_id' => $monitor->id,
            'rule_key' => 'content',
        ]);
        $this->assertSame('suspect', $monitor->fresh()->healthState());

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('回應過大，驗證未完成')
            ->assertSee('未檢查');
    }

    public function test_social_post_text_match_normalizes_markup_boundaries_and_html_entities(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $url = 'https://www.instagram.com/p/ABC123/';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'expected_text' => "Campaign headline & launch\nSpring sale",
        ]);
        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);
        $transport = new class implements ProbeTransport
        {
            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                return new HopResponse(200, null, '<article><p>Campaign</p><p>headline &amp; launch</p><p>Spring sale</p></article>', 40, null, null, null);
            }
        };
        $probe = new SecureProbeClient(app(UrlSafetyPolicy::class), $transport, new UrlMasker);

        (new RunMonitorCheck($run->id))->handle($probe, app(IncidentManager::class), app(RunScheduler::class));

        $this->assertDatabaseHas('check_runs', [
            'id' => $run->id,
            'status' => 'completed',
            'error_type' => null,
            'expected_text_found' => 1,
        ]);
        $this->assertDatabaseMissing('incidents', ['monitor_id' => $monitor->id, 'rule_key' => 'content']);
    }

    public function test_csv_preview_rejects_social_posts_that_have_no_expected_text_field(): void
    {
        $csv = "name,url,label\nThreads post,https://www.threads.com/@creator/post/ABC123,Campaign\n";
        $response = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk();

        $this->assertSame(0, $response->viewData('validCount'));
        $this->assertSame(1, $response->viewData('invalidCount'));
        $this->assertStringContainsString('社群貼文必須提供 expected_text 欄位中的預期文字', $response->viewData('previewRows')[0]['errors'][0]);
        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_csv_import_preserves_multiple_expected_text_lines_from_a_quoted_cell(): void
    {
        $csv = "name,url,label,expected_text,expected_text_mode\nSocial post,https://www.threads.com/@creator/post/MULTI123,Campaign,\"Campaign headline\nSpring sale\",present\n";
        $user = User::factory()->create();
        $response = $this->actingAs($user)
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk();

        $this->assertSame(1, $response->viewData('validCount'));
        $this->assertSame("Campaign headline\nSpring sale", $response->viewData('previewRows')[0]['expected_text']);

        $this->post(route('monitors.import.commit'), ['preview_token' => $response->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $this->assertDatabaseHas('monitors', [
            'url_hash' => Monitor::fingerprintUrl('https://www.threads.com/@creator/post/MULTI123'),
            'expected_text' => "Campaign headline\nSpring sale",
            'expected_text_mode' => 'present',
        ]);
    }

    public function test_social_post_requires_every_expected_text_line_to_be_present(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $url = 'https://www.instagram.com/p/PARTIAL123/';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'expected_text' => "Campaign headline\nSpring sale",
        ]);
        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);
        $transport = new class implements ProbeTransport
        {
            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                return new HopResponse(200, null, '<article>Campaign headline</article>', 40, null, null, null);
            }
        };
        $probe = new SecureProbeClient(app(UrlSafetyPolicy::class), $transport, new UrlMasker);

        (new RunMonitorCheck($run->id))->handle($probe, app(IncidentManager::class), app(RunScheduler::class));

        $this->assertDatabaseHas('check_runs', [
            'id' => $run->id,
            'status' => 'completed',
            'expected_text_found' => 0,
        ]);
        $this->assertDatabaseHas('incidents', [
            'monitor_id' => $monitor->id,
            'rule_key' => 'content',
            'severity' => 'warning',
        ]);
    }

    public function test_any_expected_text_condition_passes_when_one_line_is_found_and_warns_when_none_are_found(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $matchingUrl = 'https://www.instagram.com/p/ANYMATCH123/';
        $missingUrl = 'https://www.instagram.com/p/ANYMISS123/';
        $matchingMonitor = Monitor::factory()->create([
            'url' => $matchingUrl,
            'url_hash' => Monitor::fingerprintUrl($matchingUrl),
            'expected_text' => "Campaign headline\nSpring sale",
            'expected_text_mode' => 'present_any',
        ]);
        $missingMonitor = Monitor::factory()->create([
            'url' => $missingUrl,
            'url_hash' => Monitor::fingerprintUrl($missingUrl),
            'expected_text' => "Campaign headline\nSpring sale",
            'expected_text_mode' => 'present_any',
        ]);
        $matchingRun = app(RunScheduler::class)->enqueue($matchingMonitor, 'manual');
        $missingRun = app(RunScheduler::class)->enqueue($missingMonitor, 'manual');
        $this->assertNotNull($matchingRun);
        $this->assertNotNull($missingRun);
        $transport = new class implements ProbeTransport
        {
            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                $body = str_contains($target['url'], 'ANYMATCH123')
                    ? '<article>Campaign headline</article>'
                    : '<article>Other copy</article>';

                return new HopResponse(200, null, $body, 40, null, null, null);
            }
        };
        $probe = new SecureProbeClient(app(UrlSafetyPolicy::class), $transport, new UrlMasker);
        $incidents = app(IncidentManager::class);
        $scheduler = app(RunScheduler::class);

        (new RunMonitorCheck($matchingRun->id))->handle($probe, $incidents, $scheduler);
        (new RunMonitorCheck($missingRun->id))->handle($probe, $incidents, $scheduler);

        $this->assertTrue($matchingRun->fresh()->passesMonitorChecks());
        $this->assertSame('present_any', $matchingRun->fresh()->expectedTextMode());
        $this->assertDatabaseMissing('incidents', [
            'monitor_id' => $matchingMonitor->id,
            'rule_key' => 'content',
        ]);
        $this->assertFalse($missingRun->fresh()->passesMonitorChecks());
        $this->assertDatabaseHas('check_runs', [
            'id' => $missingRun->id,
            'expected_text_found' => false,
        ]);
        $incident = Incident::query()->where('monitor_id', $missingMonitor->id)->where('rule_key', 'content')->sole();
        $contentEvent = $incident->events()->where('event_type', 'failure_seen')->sole();
        $this->assertSame('present_any', $contentEvent->evidence['expected_text_mode']);
        $this->assertFalse($contentEvent->evidence['expected_text_found']);
        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $missingMonitor))
            ->assertOk()
            ->assertSee('至少包含一個片段：')
            ->assertSee('原始回應未找到任何片段');
    }

    public function test_absent_text_condition_alerts_when_any_forbidden_line_is_present(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $url = 'https://www.facebook.com/page/posts/FORBIDDEN123/';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'expected_text' => "Internal draft\nEmbargoed",
            'expected_text_mode' => 'absent',
        ]);
        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);
        $transport = new class implements ProbeTransport
        {
            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                return new HopResponse(200, null, '<article>Public launch. Embargoed details.</article>', 40, null, null, null);
            }
        };
        $probe = new SecureProbeClient(app(UrlSafetyPolicy::class), $transport, new UrlMasker);

        (new RunMonitorCheck($run->id))->handle($probe, app(IncidentManager::class), app(RunScheduler::class));

        $this->assertDatabaseHas('check_runs', [
            'id' => $run->id,
            'status' => 'completed',
            'expected_text_found' => 1,
        ]);
        $this->assertDatabaseHas('incidents', [
            'monitor_id' => $monitor->id,
            'rule_key' => 'forbidden_text',
            'severity' => 'warning',
        ]);
    }

    public function test_social_post_content_alert_explains_the_raw_http_limit_in_its_notification(): void
    {
        Config::set('monitoring.notify_email', 'ops@example.test');
        Queue::fake([SendNotificationOutbox::class]);
        $url = 'https://www.facebook.com/story.php?story_fbid=123';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'expected_text' => 'Campaign headline',
        ]);
        $run = CheckRun::factory()->for($monitor)->create(['normal_status' => 200]);
        $incident = Incident::factory()->for($monitor)->create(['rule_key' => 'content']);

        app(NotificationDispatcher::class)->queue($incident, $monitor, $run, 'opened');

        $outbox = NotificationOutbox::query()->sole();
        $this->assertStringContainsString('原因：原始回應未包含預期貼文文字', $outbox->body);
        $this->assertStringContainsString('只讀取免登入的 HTTP 回應', $outbox->body);
        $this->assertStringContainsString('不代表貼文未發布', $outbox->body);
    }

    public function test_incomplete_probe_notification_explains_why_text_was_not_verified(): void
    {
        Config::set('monitoring.notify_email', 'ops@example.test');
        Queue::fake([SendNotificationOutbox::class]);
        $url = 'https://www.instagram.com/p/ABC123/';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'expected_text' => 'Campaign headline',
        ]);
        $run = CheckRun::factory()->for($monitor)->create([
            'normal_status' => 200,
            'error_type' => 'body_too_large',
        ]);
        $incident = Incident::factory()->for($monitor)->create([
            'rule_key' => 'probe_incomplete',
            'severity' => 'warning',
        ]);

        app(NotificationDispatcher::class)->queue($incident, $monitor, $run, 'opened');

        $outbox = NotificationOutbox::query()->sole();
        $this->assertStringContainsString('原因：回應內容超過讀取上限，文字比對未完成', $outbox->body);
    }

    /** @return array{name: string, url: string, label: string, expected_text: string, expected_text_mode: string} */
    private function socialPost(string $url, string $expectedText = '', string $expectedTextMode = 'present'): array
    {
        return [
            'name' => 'Social post',
            'url' => $url,
            'label' => '',
            'expected_text' => $expectedText,
            'expected_text_mode' => $expectedTextMode,
        ];
    }
}
