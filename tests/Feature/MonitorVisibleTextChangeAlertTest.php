<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\IncidentManager;
use App\Services\Probe\ProbeResult;
use App\Services\RunScheduler;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MonitorVisibleTextChangeAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_can_enable_and_disable_visible_text_change_alerts(): void
    {
        Config::set('monitoring.transport', 'fixture');
        Queue::fake();
        $monitor = Monitor::factory()->create([
            'url' => 'http://fixture.test/visible-text-alert',
            'url_hash' => Monitor::fingerprintUrl('http://fixture.test/visible-text-alert'),
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.edit', $monitor))
            ->assertOk()
            ->assertSee('原始回應可見文字改變時建立警告')
            ->assertSee('分享預覽資訊改變時建立警告')
            ->assertSee('首次完整回應建立基準');

        $this->put(route('monitors.update', $monitor), [
            'name' => $monitor->name,
            'url' => $monitor->url,
            'label' => $monitor->label,
            'redirect_hosts' => '',
            'alert_on_visible_text_change' => '1',
            'alert_on_response_metadata_change' => '1',
        ])->assertRedirect(route('monitors.show', $monitor));

        $monitor->refresh();
        $this->assertTrue($monitor->alert_on_visible_text_change);
        $this->assertTrue($monitor->alert_on_response_metadata_change);
        $this->assertSame(2, $monitor->config_version);

        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);
        $snapshot = json_decode($run->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($snapshot['alert_on_visible_text_change']);
        $this->assertTrue($snapshot['alert_on_response_metadata_change']);

        $this->put(route('monitors.update', $monitor), [
            'name' => $monitor->name,
            'url' => $monitor->url,
            'label' => $monitor->label,
            'redirect_hosts' => '',
            'alert_on_visible_text_change' => '0',
            'alert_on_response_metadata_change' => '0',
        ])->assertRedirect(route('monitors.show', $monitor));

        $this->assertFalse($monitor->fresh()->alert_on_visible_text_change);
        $this->assertFalse($monitor->fresh()->alert_on_response_metadata_change);
        $this->assertSame(3, $monitor->fresh()->config_version);
    }

    public function test_alert_is_opt_in_and_the_first_complete_response_only_sets_a_baseline(): void
    {
        $monitor = $this->createMonitor();
        $previousHash = hash('sha256', 'original caption');
        $currentHash = hash('sha256', 'updated caption');
        $previousRun = $this->createRun($monitor, $previousHash, now()->subMinute());
        $manager = app(IncidentManager::class);

        $manager->record($monitor, $previousRun, $this->probe($monitor, $previousHash), null);
        $changedRun = $this->createRun($monitor, $currentHash, now());
        $manager->record($monitor, $changedRun, $this->probe($monitor, $currentHash), null);

        $this->assertDatabaseMissing('incidents', ['rule_key' => 'response_content_change']);

        $enabledMonitor = $this->createMonitor(alertEnabled: true);
        $baselineHash = hash('sha256', 'first caption');
        $baselineRun = $this->createRun($enabledMonitor, $baselineHash, now()->subMinute());
        $manager->record($enabledMonitor, $baselineRun, $this->probe($enabledMonitor, $baselineHash), null);

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_social_post_can_use_a_visible_text_baseline_without_an_expected_phrase(): void
    {
        $url = 'https://www.threads.com/share/BAGYFgPihu';
        $monitor = $this->createMonitor(alertEnabled: true, url: $url);
        $manager = app(IncidentManager::class);
        $firstHash = hash('sha256', 'original public post text');
        $firstRun = $this->createRun($monitor, $firstHash, now()->subMinutes(3));

        $manager->record($monitor, $firstRun, $this->probe($monitor, $firstHash), null);

        $firstRun->refresh();
        $this->assertNull($firstRun->response_visible_text_changed);
        $this->assertTrue($firstRun->hasIndeterminateContentMatch());
        $this->assertFalse($firstRun->passesMonitorChecks());
        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('已保存首次基準，等待下次檢查比較')
            ->assertSee(route('features.index').'#availability');

        $stableRun = $this->createRun($monitor, $firstHash, now()->subMinutes(2));
        $manager->record($monitor, $stableRun, $this->probe($monitor, $firstHash), null);

        $stableRun->refresh();
        $this->assertFalse($stableRun->response_visible_text_changed);
        $this->assertFalse($stableRun->hasIndeterminateContentMatch());
        $this->assertTrue($stableRun->passesMonitorChecks());

        $changedHash = hash('sha256', 'revised public post text');
        $changedRun = $this->createRun($monitor, $changedHash, now()->subMinute());
        $manager->record($monitor, $changedRun, $this->probe($monitor, $changedHash), null);

        $changedRun->refresh();
        $this->assertTrue($changedRun->response_visible_text_changed);
        $this->assertFalse($changedRun->hasIndeterminateContentMatch());
        $this->assertFalse($changedRun->passesMonitorChecks());
        $this->assertDatabaseHas('incidents', [
            'monitor_id' => $monitor->id,
            'rule_key' => 'response_content_change',
            'state' => Incident::STATE_OPEN,
        ]);
    }

    public function test_previous_text_from_an_older_configuration_is_not_used_as_a_baseline(): void
    {
        $monitor = $this->createMonitor(alertEnabled: true);
        $monitor->forceFill(['config_version' => 2])->save();
        $oldVersionHash = hash('sha256', 'old configuration caption');
        $oldVersionRun = $this->createRun($monitor, $oldVersionHash, now()->subMinute(), configVersion: 1);
        $newVersionHash = hash('sha256', 'new configuration caption');
        $newVersionRun = $this->createRun($monitor, $newVersionHash, now());

        app(IncidentManager::class)->record($monitor, $newVersionRun, $this->probe($monitor, $newVersionHash), null);

        $this->assertDatabaseMissing('incidents', ['rule_key' => 'response_content_change']);
        $this->assertDatabaseHas('check_runs', ['id' => $oldVersionRun->id, 'config_version' => 1]);
    }

    public function test_a_changed_complete_response_opens_an_immediate_private_warning_and_uses_existing_notifications(): void
    {
        Queue::fake();
        $monitor = $this->createMonitor(alertEnabled: true, notificationEmail: 'owner@example.test');
        $previousHash = hash('sha256', 'launch caption');
        $currentHash = hash('sha256', 'revised launch caption');
        $baselineRun = $this->createRun($monitor, $previousHash, now()->subMinute());
        $manager = app(IncidentManager::class);
        $manager->record($monitor, $baselineRun, $this->probe($monitor, $previousHash), null);
        $changedRun = $this->createRun($monitor, $currentHash, now());

        $manager->record($monitor, $changedRun, $this->probe($monitor, $currentHash), null);

        $incident = Incident::query()->sole();
        $this->assertSame('response_content_change', $incident->rule_key);
        $this->assertSame(Incident::STATE_OPEN, $incident->state);
        $this->assertNotNull($incident->opened_at);
        $this->assertNull($incident->confirm_after);
        $this->assertSame(['failure_seen', 'opened'], $incident->events()->orderBy('id')->pluck('event_type')->all());

        $evidence = json_encode($incident->events()->value('evidence'), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('visible_text_hash', $evidence);
        $this->assertStringNotContainsString($previousHash, $evidence);
        $this->assertStringNotContainsString($currentHash, $evidence);
        $outbox = NotificationOutbox::query()->where('incident_id', $incident->id)->sole();
        $this->assertSame('opened', $outbox->event_type);
        $this->assertSame('owner@example.test', $outbox->recipient);
        $this->assertStringContainsString('原始回應文字或分享預覽資訊已變更', $outbox->body);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('原始回應文字或分享預覽資訊已變更')
            ->assertSee('原始內容不會寫入事件')
            ->assertDontSee($previousHash)
            ->assertDontSee($currentHash);
    }

    public function test_an_incomplete_completed_check_breaks_the_comparison_baseline(): void
    {
        $monitor = $this->createMonitor(alertEnabled: true);
        $manager = app(IncidentManager::class);
        $firstHash = hash('sha256', 'caption before failed response');
        $firstRun = $this->createRun($monitor, $firstHash, now()->subMinutes(3));
        $manager->record($monitor, $firstRun, $this->probe($monitor, $firstHash), null);

        $incompleteRun = $this->createRun($monitor, null, now()->subMinutes(2), 'connection_failed');
        $manager->record($monitor, $incompleteRun, $this->probe($monitor, null, 'connection_failed'), null);

        $secondHash = hash('sha256', 'caption after failed response');
        $secondRun = $this->createRun($monitor, $secondHash, now()->subMinute());
        $manager->record($monitor, $secondRun, $this->probe($monitor, $secondHash), null);
        $this->assertDatabaseMissing('incidents', ['rule_key' => 'response_content_change']);

        $thirdHash = hash('sha256', 'third caption');
        $thirdRun = $this->createRun($monitor, $thirdHash, now());
        $manager->record($monitor, $thirdRun, $this->probe($monitor, $thirdHash), null);

        $this->assertSame(
            'response_content_change',
            Incident::query()->where('rule_key', 'response_content_change')->sole()->rule_key,
        );
    }

    public function test_stable_text_resolves_the_warning_without_a_second_notification(): void
    {
        Queue::fake();
        $monitor = $this->createMonitor(alertEnabled: true, notificationEmail: 'owner@example.test');
        $manager = app(IncidentManager::class);
        $initialHash = hash('sha256', 'original caption');
        $changedHash = hash('sha256', 'changed caption');
        $initialRun = $this->createRun($monitor, $initialHash, now()->subMinutes(2));
        $manager->record($monitor, $initialRun, $this->probe($monitor, $initialHash), null);

        $changedRun = $this->createRun($monitor, $changedHash, now()->subMinute());
        $manager->record($monitor, $changedRun, $this->probe($monitor, $changedHash), null);

        $this->travel(1)->second();
        $stableRun = $this->createRun($monitor, $changedHash, now());
        $manager->record($monitor, $stableRun, $this->probe($monitor, $changedHash), null);

        $this->travel(61)->seconds();
        $confirmedStableRun = $this->createRun($monitor, $changedHash, now());
        $manager->record($monitor, $confirmedStableRun, $this->probe($monitor, $changedHash), null);

        $incident = Incident::query()->sole();
        $this->assertSame(Incident::STATE_RESOLVED, $incident->state);
        $this->assertSame(2, $incident->recovery_count);
        $this->assertSame(1, NotificationOutbox::query()->count());
        $this->assertSame(['failure_seen', 'opened', 'recovery_seen', 'recovery_seen', 'recovered'], $incident->events()->orderBy('id')->pluck('event_type')->all());
    }

    private function createMonitor(
        bool $alertEnabled = false,
        ?string $notificationEmail = null,
        ?string $url = null,
    ): Monitor {
        $url ??= 'https://www.instagram.com/p/'.Str::upper(Str::random(12)).'/';

        return Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'alert_on_visible_text_change' => $alertEnabled,
            'notification_email' => $notificationEmail,
        ]);
    }

    private function createRun(
        Monitor $monitor,
        ?string $visibleTextHash,
        DateTimeInterface $scheduledAt,
        ?string $errorType = null,
        ?int $configVersion = null,
    ): CheckRun {
        return CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => json_encode([
                'url' => $monitor->url,
                'accepted_status_codes' => $monitor->accepted_status_codes ?? [],
                'expected_text' => $monitor->expected_text,
                'expected_text_mode' => $monitor->expected_text_mode,
                'alert_on_visible_text_change' => $monitor->alert_on_visible_text_change,
            ], JSON_THROW_ON_ERROR),
            'config_version' => $configVersion ?? $monitor->config_version,
            'scheduled_at' => $scheduledAt,
            'finished_at' => $scheduledAt,
            'error_type' => $errorType,
            'response_visible_text_hash' => $visibleTextHash,
        ]);
    }

    private function probe(Monitor $monitor, ?string $visibleTextHash, ?string $errorType = null): ProbeResult
    {
        return new ProbeResult(
            statusCode: $errorType === null ? 200 : null,
            elapsedMs: 40,
            finalUrl: $monitor->url,
            redirects: [],
            resolvedPublicIps: ['93.184.216.34'],
            errorType: $errorType,
            errorDetail: null,
            expectedTextFound: null,
            tlsExpiresAt: null,
            responseVisibleTextHash: $visibleTextHash,
        );
    }
}
