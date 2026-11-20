<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class StatusPageWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_page_only_shows_selected_monitor_status_and_sends_privacy_headers(): void
    {
        $url = 'https://private.example/landing?token=do-not-publish-this';
        $monitor = Monitor::factory()->create([
            'name' => 'Public landing page',
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'public_status' => true,
        ]);
        $token = Str::random(64);
        StatusPage::factory()->create([
            'setting_key' => 'default',
            'title' => 'Campaign availability',
            'public_token' => $token,
            'public_token_hash' => hash('sha256', $token),
            'is_published' => true,
        ]);

        $response = $this->get(route('status-pages.public', ['token' => $token]))
            ->assertOk()
            ->assertSee('Campaign availability')
            ->assertSee('Public landing page')
            ->assertSee('近期沒有預定維護時段。')
            ->assertDontSee('private.example')
            ->assertDontSee('do-not-publish-this')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->assertDatabaseHas('monitors', ['id' => $monitor->id, 'public_status' => true]);
    }

    public function test_public_history_uses_response_body_size_thresholds_from_each_check(): void
    {
        $monitor = Monitor::factory()->create([
            'public_status' => true,
            'min_response_body_bytes' => 100,
            'max_response_body_bytes' => 500,
        ]);
        $snapshot = json_encode([
            'accepted_status_codes' => [],
            'expected_text' => null,
            'expected_text_mode' => 'present',
            'min_response_body_bytes' => 100,
            'max_response_body_bytes' => 500,
        ], JSON_THROW_ON_ERROR);

        foreach ([300, 600, null] as $index => $bodyBytes) {
            $finishedAt = now()->subDays($index + 1);
            CheckRun::factory()->for($monitor)->create([
                'config_snapshot' => $snapshot,
                'source' => 'scheduled',
                'status' => 'completed',
                'scheduled_at' => $finishedAt,
                'finished_at' => $finishedAt,
                'normal_status' => 200,
                'error_type' => null,
                'response_body_bytes' => $bodyBytes,
            ]);
        }

        $token = Str::random(64);
        StatusPage::factory()->create([
            'setting_key' => 'default',
            'title' => 'Campaign availability',
            'public_token' => $token,
            'public_token_hash' => hash('sha256', $token),
            'is_published' => true,
        ]);

        $response = $this->get(route('status-pages.public', ['token' => $token]))->assertOk();
        $publicHistory = $response->viewData('monitors')->sole()->getAttribute('public_history');

        $this->assertSame(33.33, $publicHistory['success_rate']);
        $this->assertSame(1, $publicHistory['successful_checks']);
        $this->assertSame(3, $publicHistory['total_checks']);
    }

    public function test_public_page_shows_active_and_upcoming_public_maintenance_occurrences_without_private_reasons(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        $publicMonitor = Monitor::factory()->create([
            'name' => 'Public campaign service',
            'public_status' => true,
        ]);
        $activeStart = Carbon::parse('2026-11-19 19:30', 'Asia/Taipei');
        MaintenanceWindow::factory()->for($publicMonitor)->create([
            'starts_at' => $activeStart->copy()->utc(),
            'ends_at' => $activeStart->copy()->addHour()->utc(),
            'reason' => 'private active deployment reason',
        ]);
        $upcomingStart = Carbon::parse('2026-11-20 09:00', 'Asia/Taipei');
        MaintenanceWindow::factory()->for($publicMonitor)->create([
            'starts_at' => $upcomingStart->copy()->utc(),
            'ends_at' => $upcomingStart->copy()->addHour()->utc(),
            'reason' => 'private one-time reason',
        ]);
        $weeklyStart = Carbon::parse('2026-11-19 21:00', 'Asia/Taipei');
        MaintenanceWindow::factory()->for($publicMonitor)->create([
            'starts_at' => $weeklyStart->copy()->utc(),
            'ends_at' => $weeklyStart->copy()->addHour()->utc(),
            'repeat_weekly' => true,
            'repeat_until' => '2026-11-26',
            'reason' => 'private weekly reason',
        ]);
        $outsideLookaheadStart = Carbon::parse('2026-12-25 10:00', 'Asia/Taipei');
        MaintenanceWindow::factory()->for($publicMonitor)->create([
            'starts_at' => $outsideLookaheadStart->copy()->utc(),
            'ends_at' => $outsideLookaheadStart->copy()->addHour()->utc(),
            'reason' => 'outside the public schedule window',
        ]);
        $privateMonitorUrl = 'https://private-monitor.example/status';
        $privateMonitor = Monitor::factory()->create([
            'name' => 'Private internal monitor',
            'url' => $privateMonitorUrl,
            'url_hash' => Monitor::fingerprintUrl($privateMonitorUrl),
            'public_status' => false,
        ]);
        MaintenanceWindow::factory()->for($privateMonitor)->create([
            'starts_at' => $weeklyStart->copy()->utc(),
            'ends_at' => $weeklyStart->copy()->addHour()->utc(),
            'reason' => 'private monitor reason',
        ]);
        $token = Str::random(64);
        StatusPage::factory()->create([
            'setting_key' => 'default',
            'public_token' => $token,
            'public_token_hash' => hash('sha256', $token),
            'is_published' => true,
        ]);

        $response = $this->get(route('status-pages.public', ['token' => $token]))
            ->assertOk()
            ->assertSee('預定維護')
            ->assertSee('維護中')
            ->assertSee('即將維護')
            ->assertSee('2026/11/19 19:30')
            ->assertSee('2026/11/20 09:00')
            ->assertSee('2026/11/26 21:00')
            ->assertDontSee('private active deployment reason')
            ->assertDontSee('private one-time reason')
            ->assertDontSee('private weekly reason')
            ->assertDontSee('outside the public schedule window')
            ->assertDontSee('private monitor reason')
            ->assertDontSee('private internal monitor');

        $publicMaintenanceWindows = $response->viewData('publicMaintenanceWindows');

        $this->assertCount(4, $publicMaintenanceWindows);
        $this->assertSame(1, $publicMaintenanceWindows->where('state', 'active')->count());
        $this->assertSame(3, $publicMaintenanceWindows->where('state', 'upcoming')->count());
        $this->assertSame(
            '2026-11-19 19:30',
            $publicMaintenanceWindows->first()['starts_at']->format('Y-m-d H:i'),
        );
    }

    public function test_status_page_requires_a_selected_monitor_and_rotates_old_links(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->post(route('status-pages.update'), ['title' => 'Campaign', 'is_published' => true])
            ->assertSessionHasErrors('is_published');

        $this->assertDatabaseCount('status_pages', 0);
        $url = 'https://public.example/landing';
        Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'public_status' => true,
        ]);

        $this->post(route('status-pages.update'), ['title' => 'Campaign', 'is_published' => true])
            ->assertRedirect(route('status-pages.edit'));
        $statusPage = StatusPage::query()->where('setting_key', 'default')->firstOrFail();
        $oldToken = $statusPage->public_token;

        $this->get(route('status-pages.public', ['token' => $oldToken]))->assertOk();
        $this->post(route('status-pages.update'), [
            'title' => 'Campaign',
            'is_published' => true,
            'rotate_public_link' => '1',
        ])->assertRedirect(route('status-pages.edit'));

        $newToken = $statusPage->fresh()->public_token;
        $this->assertNotSame($oldToken, $newToken);
        $this->get(route('status-pages.public', ['token' => $oldToken]))->assertNotFound();
        $this->get(route('status-pages.public', ['token' => $newToken]))->assertOk();
    }

    public function test_public_page_shows_aggregated_history_for_scheduled_checks_without_exposing_monitor_details(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 00:30:00', 'UTC'));
        $monitor = Monitor::factory()->create([
            'name' => 'Public campaign page',
            'url' => 'https://private.example/landing?campaign=private',
            'url_hash' => Monitor::fingerprintUrl('https://private.example/landing?campaign=private'),
            'expected_text' => 'Sensitive campaign phrase',
            'public_status' => true,
        ]);
        $snapshot = $this->configSnapshot('Sensitive campaign phrase');
        $twoDaysAgo = Carbon::parse('2026-11-17 10:00:00', 'Asia/Taipei')->utc();
        $yesterday = Carbon::parse('2026-11-18 10:00:00', 'Asia/Taipei')->utc();

        CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $snapshot,
            'source' => 'scheduled',
            'scheduled_at' => $twoDaysAgo,
            'finished_at' => $twoDaysAgo,
            'normal_status' => 200,
            'expected_text_found' => true,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $snapshot,
            'source' => 'scheduled',
            'scheduled_at' => $twoDaysAgo->copy()->addHour(),
            'finished_at' => $twoDaysAgo->copy()->addHour(),
            'normal_status' => 200,
            'expected_text_found' => false,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $snapshot,
            'source' => 'scheduled',
            'scheduled_at' => $yesterday,
            'finished_at' => $yesterday,
            'normal_status' => 200,
            'expected_text_found' => true,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $snapshot,
            'source' => 'manual',
            'scheduled_at' => $yesterday->copy()->addHour(),
            'finished_at' => $yesterday->copy()->addHour(),
            'normal_status' => 200,
            'expected_text_found' => false,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $snapshot,
            'source' => 'tls_daily',
            'scheduled_at' => $yesterday->copy()->addHours(2),
            'finished_at' => $yesterday->copy()->addHours(2),
            'status' => 'failed',
            'normal_status' => 200,
            'expected_text_found' => false,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $snapshot,
            'source' => 'scheduled',
            'scheduled_at' => Carbon::parse('2026-10-20 10:00:00', 'Asia/Taipei')->utc(),
            'finished_at' => Carbon::parse('2026-10-20 10:00:00', 'Asia/Taipei')->utc(),
            'normal_status' => 500,
            'expected_text_found' => false,
        ]);
        $privateMonitor = Monitor::factory()->create(['public_status' => false]);
        CheckRun::factory()->for($privateMonitor)->create([
            'config_snapshot' => $snapshot,
            'source' => 'scheduled',
            'scheduled_at' => $yesterday,
            'finished_at' => $yesterday,
            'normal_status' => 500,
            'expected_text_found' => false,
        ]);
        $token = Str::random(64);
        StatusPage::factory()->create([
            'setting_key' => 'default',
            'title' => 'Campaign availability',
            'public_token' => $token,
            'public_token_hash' => hash('sha256', $token),
            'is_published' => true,
        ]);

        $response = $this->get(route('status-pages.public', ['token' => $token]))
            ->assertOk()
            ->assertSee('近 30 天檢查成功率')
            ->assertSee('66.67%')
            ->assertSee('50.00%')
            ->assertSee('100.00%')
            ->assertSee('2026-11-17')
            ->assertSee('查看每日檢查明細')
            ->assertDontSee('private.example')
            ->assertDontSee('Sensitive campaign phrase');

        $publicHistory = $response->viewData('monitors')->sole()->getAttribute('public_history');

        $this->assertSame(66.67, $publicHistory['success_rate']);
        $this->assertSame(2, $publicHistory['successful_checks']);
        $this->assertSame(3, $publicHistory['total_checks']);
    }

    public function test_public_page_marks_history_unknown_when_no_scheduled_checks_have_ended(): void
    {
        $monitor = Monitor::factory()->create([
            'name' => 'New campaign page',
            'public_status' => true,
        ]);
        $token = Str::random(64);
        StatusPage::factory()->create([
            'setting_key' => 'default',
            'title' => 'Campaign availability',
            'public_token' => $token,
            'public_token_hash' => hash('sha256', $token),
            'is_published' => true,
        ]);

        $response = $this->get(route('status-pages.public', ['token' => $token]))
            ->assertOk()
            ->assertSee('近 30 天檢查成功率')
            ->assertSee('無檢查資料')
            ->assertSee('查看每日檢查明細');

        $publicHistory = $response->viewData('monitors')->sole()->getAttribute('public_history');

        $this->assertNull($publicHistory['success_rate']);
        $this->assertSame(0, $publicHistory['total_checks']);
        $this->assertCount(30, $publicHistory['days']);
        $this->assertSame(30, collect($publicHistory['days'])->where('state', 'unknown')->count());
    }

    public function test_public_page_shows_time_weighted_availability_and_coverage_without_private_monitor_data(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 00:30:00', 'UTC'));
        Config::set('monitoring.stale_grace_seconds', 0);
        $monitor = Monitor::factory()->create([
            'name' => 'Public campaign page',
            'url' => 'https://private.example/landing?campaign=private',
            'url_hash' => Monitor::fingerprintUrl('https://private.example/landing?campaign=private'),
            'expected_text' => 'Sensitive campaign phrase',
            'public_status' => true,
            'interval_seconds' => 3600,
        ]);
        $snapshot = $this->configSnapshot(null, 3600);
        $firstFinishedAt = now()->subHours(2);
        $secondFinishedAt = now()->subHour();

        CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $snapshot,
            'source' => 'scheduled',
            'scheduled_at' => $firstFinishedAt,
            'finished_at' => $firstFinishedAt,
            'normal_status' => 200,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $snapshot,
            'source' => 'scheduled',
            'scheduled_at' => $secondFinishedAt,
            'finished_at' => $secondFinishedAt,
            'normal_status' => 500,
        ]);
        $privateMonitor = Monitor::factory()->create([
            'public_status' => false,
            'interval_seconds' => 3600,
        ]);
        CheckRun::factory()->for($privateMonitor)->create([
            'config_snapshot' => $snapshot,
            'source' => 'scheduled',
            'finished_at' => now()->subMinutes(30),
            'normal_status' => 500,
        ]);
        $token = Str::random(64);
        StatusPage::factory()->create([
            'setting_key' => 'default',
            'public_token' => $token,
            'public_token_hash' => hash('sha256', $token),
            'is_published' => true,
        ]);

        $response = $this->get(route('status-pages.public', ['token' => $token]))
            ->assertOk()
            ->assertSee('近 30 天估算時間可用率')
            ->assertSee('50.00%')
            ->assertSee('0.28%')
            ->assertSee('120.0 / 43,200.0 分鐘')
            ->assertDontSee('private.example')
            ->assertDontSee('Sensitive campaign phrase');

        $publicHistory = $response->viewData('monitors')->sole()->getAttribute('public_history');
        $availability = $publicHistory['availability'];

        $this->assertSame(50.0, $availability['availability_rate']);
        $this->assertSame(0.28, $availability['coverage_rate']);
        $this->assertSame(60.0, $availability['up_minutes']);
        $this->assertSame(120.0, $availability['observed_minutes']);
        $this->assertSame(43200.0, $availability['eligible_minutes']);
    }

    public function test_public_page_shows_current_and_recent_incidents_without_exposing_private_details(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        $monitor = Monitor::factory()->create([
            'name' => 'Public campaign page',
            'url' => 'https://public.example/campaign',
            'url_hash' => Monitor::fingerprintUrl('https://public.example/campaign'),
            'public_status' => true,
        ]);
        Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_OPEN,
            'rule_key' => 'content',
            'note' => 'internal active incident note',
            'opened_at' => Carbon::parse('2026-11-18 01:00:00', 'UTC'),
            'last_seen_at' => Carbon::parse('2026-11-18 02:00:00', 'UTC'),
        ]);
        Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_RESOLVED,
            'rule_key' => 'http',
            'note' => 'internal resolved incident note',
            'opened_at' => Carbon::parse('2026-11-16 01:00:00', 'UTC'),
            'last_seen_at' => Carbon::parse('2026-11-16 02:00:00', 'UTC'),
            'resolved_at' => Carbon::parse('2026-11-16 03:00:00', 'UTC'),
        ]);
        Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_SUSPECT,
            'note' => 'unconfirmed incident note',
            'opened_at' => null,
        ]);
        Incident::factory()->for($monitor)->create([
            'config_version' => 0,
            'state' => Incident::STATE_OPEN,
            'note' => 'outdated configuration incident note',
        ]);
        $privateMonitor = Monitor::factory()->create([
            'name' => 'Private internal monitor',
            'url' => 'https://private.example/internal',
            'url_hash' => Monitor::fingerprintUrl('https://private.example/internal'),
            'public_status' => false,
        ]);
        Incident::factory()->for($privateMonitor)->create([
            'note' => 'private incident note',
        ]);
        $token = Str::random(64);
        StatusPage::factory()->create([
            'setting_key' => 'default',
            'public_token' => $token,
            'public_token_hash' => hash('sha256', $token),
            'is_published' => true,
        ]);

        $response = $this->get(route('status-pages.public', ['token' => $token]))
            ->assertOk()
            ->assertSee('事件狀態時間')
            ->assertSee('近期事件')
            ->assertSee('進行中')
            ->assertSee('已恢復')
            ->assertSee('2026/11/18 09:00:00')
            ->assertSee('2026/11/16 09:00:00')
            ->assertDontSee('internal active incident note')
            ->assertDontSee('internal resolved incident note')
            ->assertDontSee('unconfirmed incident note')
            ->assertDontSee('outdated configuration incident note')
            ->assertDontSee('private incident note')
            ->assertDontSee('Private internal monitor')
            ->assertDontSee('原始回應未包含預期文字');

        $this->assertCount(1, $response->viewData('activePublicIncidents'));
        $this->assertCount(1, $response->viewData('recentResolvedPublicIncidents'));
    }

    public function test_public_page_omits_old_and_false_alarm_incidents_from_recent_history(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        $monitor = Monitor::factory()->create(['public_status' => true]);
        Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_RESOLVED,
            'classification' => 'false_alarm',
            'note' => 'false alarm private note',
            'opened_at' => Carbon::parse('2026-11-17 01:00:00', 'UTC'),
            'resolved_at' => Carbon::parse('2026-11-17 02:00:00', 'UTC'),
        ]);
        Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_RESOLVED,
            'note' => 'old private incident note',
            'opened_at' => Carbon::parse('2026-10-01 01:00:00', 'UTC'),
            'resolved_at' => Carbon::parse('2026-10-01 02:00:00', 'UTC'),
        ]);
        $token = Str::random(64);
        StatusPage::factory()->create([
            'setting_key' => 'default',
            'public_token' => $token,
            'public_token_hash' => hash('sha256', $token),
            'is_published' => true,
        ]);

        $response = $this->get(route('status-pages.public', ['token' => $token]))
            ->assertOk()
            ->assertSee('近 30 天沒有已確認或已恢復的事件。')
            ->assertDontSee('false alarm private note')
            ->assertDontSee('old private incident note');

        $this->assertCount(0, $response->viewData('recentResolvedPublicIncidents'));
    }

    public function test_public_page_limits_resolved_incidents_to_the_twenty_most_recent_records(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        $monitor = Monitor::factory()->create([
            'url' => 'https://public.example/incident-history',
            'url_hash' => Monitor::fingerprintUrl('https://public.example/incident-history'),
            'public_status' => true,
        ]);

        for ($incidentNumber = 1; $incidentNumber <= 21; $incidentNumber++) {
            $resolvedAt = Carbon::parse('2026-11-01 00:00:00', 'UTC')->addMinutes($incidentNumber);
            Incident::factory()->for($monitor)->create([
                'state' => Incident::STATE_RESOLVED,
                'opened_at' => $resolvedAt->copy()->subMinute(),
                'resolved_at' => $resolvedAt,
            ]);
        }

        $token = Str::random(64);
        StatusPage::factory()->create([
            'setting_key' => 'default',
            'public_token' => $token,
            'public_token_hash' => hash('sha256', $token),
            'is_published' => true,
        ]);

        $response = $this->get(route('status-pages.public', ['token' => $token]))->assertOk();
        $recentIncidents = $response->viewData('recentResolvedPublicIncidents');

        $this->assertCount(20, $recentIncidents);
        $this->assertSame(
            Carbon::parse('2026-11-01 00:21:00', 'UTC')->getTimestamp(),
            $recentIncidents->first()['resolved_at']->getTimestamp(),
        );
    }

    private function configSnapshot(?string $expectedText, int $intervalSeconds = 300): string
    {
        return json_encode([
            'name' => 'Public campaign page',
            'url' => 'https://private.example/landing',
            'accepted_status_codes' => [],
            'expected_text' => $expectedText,
            'expected_text_mode' => 'present',
            'response_time_threshold_ms' => null,
            'interval_seconds' => $intervalSeconds,
            'allowed_redirect_hosts' => [],
        ], JSON_THROW_ON_ERROR);
    }
}
