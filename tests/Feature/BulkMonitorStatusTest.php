<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BulkMonitorStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_pause_selected_monitors_and_their_queued_work(): void
    {
        $firstMonitor = $this->createMonitor();
        $secondMonitor = $this->createMonitor();
        $untouchedMonitor = $this->createMonitor();
        $queuedRun = CheckRun::factory()->for($firstMonitor)->create([
            'status' => 'queued',
            'started_at' => null,
            'finished_at' => null,
        ]);
        $runningRun = CheckRun::factory()->for($firstMonitor)->create([
            'status' => 'running',
            'finished_at' => null,
        ]);
        $openIncident = Incident::factory()->for($firstMonitor)->create([
            'state' => Incident::STATE_OPEN,
        ]);
        $suspectIncident = Incident::factory()->for($secondMonitor)->create([
            'state' => Incident::STATE_SUSPECT,
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'pause',
                'interval_seconds' => '',
                'monitor_ids' => [$firstMonitor->id, $secondMonitor->id],
            ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHas('status', '已暫停 2 個監測項目。');

        foreach ([$firstMonitor, $secondMonitor] as $monitor) {
            $this->assertDatabaseHas('monitors', [
                'id' => $monitor->id,
                'enabled' => false,
                'next_check_at' => null,
            ]);
        }

        $this->assertDatabaseHas('check_runs', [
            'id' => $queuedRun->id,
            'status' => 'paused',
        ]);
        $this->assertNotNull($queuedRun->fresh()->finished_at);
        $this->assertDatabaseHas('check_runs', [
            'id' => $runningRun->id,
            'status' => 'running',
            'finished_at' => null,
        ]);
        $this->assertDatabaseHas('incidents', [
            'id' => $openIncident->id,
            'state' => Incident::STATE_PAUSED,
            'active_key' => null,
            'resolved_at' => null,
        ]);
        $this->assertDatabaseHas('incidents', [
            'id' => $suspectIncident->id,
            'state' => Incident::STATE_PAUSED,
            'active_key' => null,
            'resolved_at' => null,
        ]);
        $this->assertDatabaseCount('incident_events', 2);
        $this->assertDatabaseHas('incident_events', [
            'incident_id' => $openIncident->id,
            'event_type' => 'paused',
        ]);
        $this->assertDatabaseHas('incident_events', [
            'incident_id' => $suspectIncident->id,
            'event_type' => 'paused',
        ]);
        $this->assertTrue($untouchedMonitor->fresh()->enabled);
        $this->assertNotNull($untouchedMonitor->fresh()->next_check_at);
    }

    public function test_bulk_resume_schedules_only_paused_monitors_for_an_immediate_check(): void
    {
        $this->travelTo(now()->startOfMinute());
        $pausedMonitor = $this->createMonitor([
            'enabled' => false,
            'next_check_at' => null,
        ]);
        $alreadyEnabledMonitor = $this->createMonitor([
            'enabled' => true,
            'next_check_at' => now()->addHour(),
        ]);
        $existingDueAt = $alreadyEnabledMonitor->next_check_at->toDateTimeString();

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'resume',
                'monitor_ids' => [$pausedMonitor->id, $alreadyEnabledMonitor->id],
            ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHas('status', '已恢復 1 個監測項目。 1 個原本已啟用，未變更。');

        $this->assertDatabaseHas('monitors', [
            'id' => $pausedMonitor->id,
            'enabled' => true,
            'next_check_at' => now()->toDateTimeString(),
        ]);
        $this->assertSame($existingDueAt, $alreadyEnabledMonitor->fresh()->next_check_at->toDateTimeString());
    }

    public function test_bulk_manual_check_queues_selected_monitors_without_changing_their_schedules(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $nextCheckAt = now()->addHour();
        $firstMonitor = $this->createMonitor(['next_check_at' => $nextCheckAt]);
        $secondMonitor = $this->createMonitor(['next_check_at' => $nextCheckAt]);
        Cache::forget('manual-check:'.$firstMonitor->id);
        Cache::forget('manual-check:'.$secondMonitor->id);

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'check',
                'monitor_ids' => [$firstMonitor->id, $secondMonitor->id],
            ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHas('status', '已排入 2 個手動檢查。');

        foreach ([$firstMonitor, $secondMonitor] as $monitor) {
            $this->assertDatabaseHas('check_runs', [
                'monitor_id' => $monitor->id,
                'source' => 'manual',
                'status' => 'queued',
            ]);
            $this->assertSame($nextCheckAt->toDateTimeString(), $monitor->fresh()->next_check_at->toDateTimeString());
            Cache::forget('manual-check:'.$monitor->id);
        }

        Queue::assertPushed(RunMonitorCheck::class, 2);
    }

    public function test_bulk_manual_check_skips_paused_cooling_and_in_flight_monitors(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $pausedMonitor = $this->createMonitor(['enabled' => false]);
        $coolingMonitor = $this->createMonitor();
        $inFlightMonitor = $this->createMonitor();
        $availableMonitor = $this->createMonitor();
        Cache::put('manual-check:'.$coolingMonitor->id, true, now()->addMinutes(5));
        $existingRun = CheckRun::factory()->for($inFlightMonitor)->create([
            'status' => 'queued',
            'started_at' => null,
            'finished_at' => null,
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'check',
                'monitor_ids' => [$pausedMonitor->id, $coolingMonitor->id, $inFlightMonitor->id, $availableMonitor->id],
            ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHas('status', '已排入 1 個手動檢查。 1 個已暫停，略過。 1 個仍在五分鐘冷卻期，略過。 1 個已有檢查排入佇列或正在執行，略過。');

        $this->assertDatabaseHas('check_runs', [
            'id' => $existingRun->id,
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('check_runs', [
            'monitor_id' => $availableMonitor->id,
            'source' => 'manual',
            'status' => 'queued',
        ]);
        $this->assertDatabaseCount('check_runs', 2);
        Queue::assertPushed(RunMonitorCheck::class, 1);

        foreach ([$coolingMonitor, $inFlightMonitor, $availableMonitor] as $monitor) {
            Cache::forget('manual-check:'.$monitor->id);
        }
    }

    public function test_bulk_status_rejects_empty_duplicate_and_unknown_monitor_selections(): void
    {
        $monitor = $this->createMonitor();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), ['action' => 'pause'])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHasErrors(['monitor_ids']);

        $this->actingAs($user)
            ->from(route('monitors.index'))
            ->followingRedirects()
            ->post(route('monitors.bulk-status'), [
                'action' => 'pause',
                'monitor_ids' => [$monitor->id, $monitor->id],
            ])
            ->assertOk()
            ->assertSee('監測項目不可重複選取。');

        $this->actingAs($user)
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'resume',
                'monitor_ids' => [999999],
            ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHasErrors(['monitor_ids.0']);

        $this->assertTrue($monitor->fresh()->enabled);
    }

    public function test_bulk_status_rejects_more_than_fifty_monitors_without_changing_any(): void
    {
        $monitorIds = [];

        for ($index = 0; $index < 51; $index++) {
            $monitorIds[] = $this->createMonitor()->id;
        }

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'pause',
                'monitor_ids' => $monitorIds,
            ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHasErrors(['monitor_ids']);

        $this->assertSame(51, Monitor::query()->where('enabled', true)->count());
    }

    public function test_bulk_label_updates_selected_monitors_without_changing_check_or_incident_state(): void
    {
        $firstMonitor = $this->createMonitor(['name' => 'Campaign one', 'label' => 'Old group']);
        $secondMonitor = $this->createMonitor(['name' => 'Campaign two', 'label' => null]);
        $untouchedMonitor = $this->createMonitor(['name' => 'Other campaign', 'label' => 'Other']);
        $queuedRun = CheckRun::factory()->for($firstMonitor)->create([
            'status' => 'queued',
            'started_at' => null,
            'finished_at' => null,
        ]);
        $incident = Incident::factory()->for($firstMonitor)->create(['state' => Incident::STATE_OPEN]);
        $configVersion = $firstMonitor->config_version;
        $activeKey = $incident->active_key;

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'label',
                'label' => 'Campaign',
                'monitor_ids' => [$firstMonitor->id, $secondMonitor->id],
            ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHas('status', '已更新 2 個監測項目標籤為「Campaign」。');

        $this->assertSame('Campaign', $firstMonitor->fresh()->label);
        $this->assertSame('Campaign', $secondMonitor->fresh()->label);
        $this->assertSame('Other', $untouchedMonitor->fresh()->label);
        $this->assertSame($configVersion, $firstMonitor->fresh()->config_version);
        $this->assertDatabaseHas('check_runs', ['id' => $queuedRun->id, 'status' => 'queued']);
        $this->assertDatabaseHas('incidents', [
            'id' => $incident->id,
            'state' => Incident::STATE_OPEN,
            'active_key' => $activeKey,
        ]);
        $this->assertDatabaseCount('incident_events', 0);

        $this->get(route('monitors.index', ['label' => 'Campaign']))
            ->assertOk()
            ->assertSee('Campaign one')
            ->assertSee('Campaign two')
            ->assertDontSee('Other campaign');
    }

    public function test_bulk_label_can_be_cleared_and_reports_unchanged_items(): void
    {
        $labeledMonitor = $this->createMonitor(['label' => 'Campaign']);
        $unlabeledMonitor = $this->createMonitor(['label' => null]);

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'label',
                'label' => '',
                'monitor_ids' => [$labeledMonitor->id, $unlabeledMonitor->id],
            ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHas('status', '已更新 1 個監測項目標籤（已清除）。 1 個標籤已相同，未變更。');

        $this->assertNull($labeledMonitor->fresh()->label);
        $this->assertNull($unlabeledMonitor->fresh()->label);
    }

    public function test_bulk_label_rejects_overlong_labels_without_changing_any_monitor(): void
    {
        $monitor = $this->createMonitor(['label' => 'Existing']);

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'label',
                'label' => str_repeat('a', 101),
                'monitor_ids' => [$monitor->id],
            ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHasErrors('label');

        $this->assertSame('Existing', $monitor->fresh()->label);
    }

    public function test_bulk_interval_change_updates_selected_schedules_and_supersedes_old_configuration(): void
    {
        $this->travelTo(now()->startOfMinute());
        $changedMonitor = $this->createMonitor([
            'interval_seconds' => 900,
            'next_check_at' => now()->addHour(),
        ]);
        $unchangedMonitor = $this->createMonitor([
            'interval_seconds' => 300,
            'next_check_at' => now()->addHour(),
        ]);
        $pausedMonitor = $this->createMonitor([
            'enabled' => false,
            'interval_seconds' => 900,
            'next_check_at' => null,
        ]);
        $untouchedMonitor = $this->createMonitor([
            'interval_seconds' => 900,
            'next_check_at' => now()->addHour(),
        ]);
        $queuedRun = CheckRun::factory()->for($changedMonitor)->create([
            'config_version' => 1,
            'status' => 'queued',
            'started_at' => null,
            'finished_at' => null,
        ]);
        $incident = Incident::factory()->for($changedMonitor)->create([
            'config_version' => 1,
            'state' => Incident::STATE_OPEN,
            'confirm_after' => now()->addMinute(),
        ]);
        $unchangedDueAt = $unchangedMonitor->next_check_at->toDateTimeString();
        $untouchedDueAt = $untouchedMonitor->next_check_at->toDateTimeString();

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'interval',
                'interval_seconds' => 300,
                'monitor_ids' => [$changedMonitor->id, $unchangedMonitor->id, $pausedMonitor->id],
            ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHas('status', '已調整 2 個監測項目的檢查頻率為「每 5 分鐘」。 1 個項目已是此頻率，未變更。');

        $this->assertDatabaseHas('monitors', [
            'id' => $changedMonitor->id,
            'interval_seconds' => 300,
            'config_version' => 2,
            'next_check_at' => now()->toDateTimeString(),
        ]);
        $this->assertDatabaseHas('monitors', [
            'id' => $pausedMonitor->id,
            'enabled' => false,
            'interval_seconds' => 300,
            'config_version' => 2,
            'next_check_at' => null,
        ]);
        $this->assertDatabaseHas('monitors', [
            'id' => $unchangedMonitor->id,
            'interval_seconds' => 300,
            'config_version' => 1,
        ]);
        $this->assertSame($unchangedDueAt, $unchangedMonitor->fresh()->next_check_at->toDateTimeString());
        $this->assertDatabaseHas('monitors', [
            'id' => $untouchedMonitor->id,
            'interval_seconds' => 900,
            'config_version' => 1,
        ]);
        $this->assertSame($untouchedDueAt, $untouchedMonitor->fresh()->next_check_at->toDateTimeString());
        $this->assertDatabaseHas('check_runs', [
            'id' => $queuedRun->id,
            'status' => 'stale_config',
            'finished_at' => now()->toDateTimeString(),
        ]);
        $this->assertDatabaseHas('incidents', [
            'id' => $incident->id,
            'state' => Incident::STATE_SUPERSEDED,
            'active_key' => null,
            'confirm_after' => null,
        ]);
        $this->assertDatabaseHas('incident_events', [
            'incident_id' => $incident->id,
            'event_type' => 'superseded',
        ]);
    }

    #[DataProvider('invalidBulkIntervalValues')]
    public function test_bulk_interval_change_rejects_values_outside_supported_frequency_options(
        string $intervalSeconds,
        string $errorMessage,
    ): void {
        $monitor = $this->createMonitor();

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'interval',
                'interval_seconds' => $intervalSeconds,
                'monitor_ids' => [$monitor->id],
            ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHasErrors(['interval_seconds' => $errorMessage]);

        $this->assertDatabaseHas('monitors', [
            'id' => $monitor->id,
            'interval_seconds' => 900,
            'config_version' => 1,
        ]);
        $this->assertDatabaseCount('check_runs', 0);
        $this->assertDatabaseCount('incident_events', 0);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidBulkIntervalValues(): array
    {
        return [
            'frequency is missing' => ['', '選擇此批次操作時，請指定檢查頻率。'],
            'frequency is not an integer' => ['quickly', '檢查頻率必須是秒數。'],
            'frequency is unsupported' => ['120', '請選擇支援的檢查頻率。'],
        ];
    }

    public function test_bulk_delete_requires_explicit_confirmation_and_keeps_monitor_data_on_failure(): void
    {
        $monitor = $this->createMonitor();
        $checkRun = CheckRun::factory()->for($monitor)->create();

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'delete',
                'monitor_ids' => [$monitor->id],
            ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHasErrors('confirm_delete');

        $this->assertModelExists($monitor);
        $this->assertModelExists($checkRun);
    }

    public function test_bulk_delete_removes_selected_monitors_and_their_related_records(): void
    {
        $firstMonitor = $this->createMonitor();
        $secondMonitor = $this->createMonitor();
        $untouchedMonitor = $this->createMonitor();
        $checkRun = CheckRun::factory()->for($firstMonitor)->create();
        $incident = Incident::factory()->for($firstMonitor)->create();
        $incidentEvent = IncidentEvent::factory()->for($incident)->for($checkRun)->create();
        $outbox = NotificationOutbox::factory()->for($incident)->create();

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'delete',
                'confirm_delete' => '1',
                'monitor_ids' => [$firstMonitor->id, $secondMonitor->id],
            ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHas('status', '已刪除 2 個監測項目及其相關紀錄。');

        $this->assertModelMissing($firstMonitor);
        $this->assertModelMissing($secondMonitor);
        $this->assertModelExists($untouchedMonitor);
        $this->assertModelMissing($checkRun);
        $this->assertModelMissing($incident);
        $this->assertModelMissing($incidentEvent);
        $this->assertModelMissing($outbox);
    }

    public function test_individual_delete_still_unpublishes_the_page_when_it_removes_the_last_public_monitor(): void
    {
        $statusPage = StatusPage::factory()->create([
            'setting_key' => 'default',
            'is_published' => true,
        ]);
        $monitor = $this->createMonitor(['public_status' => true]);

        $this->actingAs(User::factory()->create())
            ->delete(route('monitors.destroy', $monitor))
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHas('status', '監測項目及其相關記錄已刪除；最後一個公開項目已移除，狀態頁也已取消發布。');

        $this->assertModelMissing($monitor);
        $this->assertFalse($statusPage->fresh()->is_published);
    }

    public function test_bulk_delete_unpublishes_the_status_page_only_after_removing_its_last_public_monitor(): void
    {
        $statusPage = StatusPage::factory()->create([
            'setting_key' => 'default',
            'is_published' => true,
        ]);
        $firstPublicMonitor = $this->createMonitor(['public_status' => true]);
        $lastPublicMonitor = $this->createMonitor(['public_status' => true]);
        $privateMonitor = $this->createMonitor();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'delete',
                'confirm_delete' => '1',
                'monitor_ids' => [$firstPublicMonitor->id, $privateMonitor->id],
            ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHas('status', '已刪除 2 個監測項目及其相關紀錄。');

        $this->assertTrue($statusPage->fresh()->is_published);

        $this->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'delete',
                'confirm_delete' => '1',
                'monitor_ids' => [$lastPublicMonitor->id],
            ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHas('status', '已刪除 1 個監測項目及其相關紀錄；最後一個公開項目已移除，狀態頁也已取消發布。');

        $this->assertFalse($statusPage->fresh()->is_published);
    }

    public function test_guest_cannot_bulk_delete_monitors(): void
    {
        $monitor = $this->createMonitor();

        $this->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'delete',
                'confirm_delete' => '1',
                'monitor_ids' => [$monitor->id],
            ])
            ->assertRedirect(route('login'));

        $this->assertModelExists($monitor);
    }

    public function test_guest_cannot_submit_bulk_monitor_status_changes(): void
    {
        $monitor = $this->createMonitor();

        $this->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'pause',
                'monitor_ids' => [$monitor->id],
            ])
            ->assertRedirect(route('login'));

        $this->assertTrue($monitor->fresh()->enabled);
    }

    public function test_monitor_list_renders_bulk_controls_and_selectable_rows(): void
    {
        $monitor = $this->createMonitor(['name' => 'Campaign status']);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.index'))
            ->assertOk()
            ->assertSee(route('monitors.bulk-status'), false)
            ->assertSee('刪除所選監測項目')
            ->assertSee('name="confirm_delete"', false)
            ->assertSee('data-monitor-select-all', false)
            ->assertSee('data-monitor-select', false)
            ->assertSee('name="monitor_ids[]" value="'.$monitor->id.'"', false)
            ->assertSee('暫停所選監測項目')
            ->assertSee('恢復所選監測項目')
            ->assertSee('更新所選項目的標籤')
            ->assertSee('立即檢查所選項目')
            ->assertSee('匯出所選項目 CSV')
            ->assertSee('調整所選項目的檢查頻率')
            ->assertSee('name="interval_seconds"', false)
            ->assertSee('bulk-monitor-interval', false)
            ->assertSee('name="label"', false)
            ->assertSee('留空會清除所選項目的標籤');
    }

    public function test_bulk_export_downloads_only_selected_monitors_and_keeps_csv_safety(): void
    {
        $selectedMonitor = Monitor::factory()->create([
            'name' => '=Selected campaign',
            'url' => 'https://example.test/selected?token=private-token',
            'url_hash' => Monitor::fingerprintUrl('https://example.test/selected?token=private-token'),
            'label' => 'Launch',
        ]);
        $anotherSelectedMonitor = $this->createMonitor(['name' => 'Another selected campaign']);
        $unselectedMonitor = $this->createMonitor(['name' => 'Unselected campaign']);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('monitors.bulk-status'), [
                'action' => 'export',
                'monitor_ids' => [$selectedMonitor->id, $anotherSelectedMonitor->id],
            ])
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();

        $this->assertStringContainsString("'=Selected campaign", $content);
        $this->assertStringContainsString('Another selected campaign', $content);
        $this->assertStringNotContainsString($unselectedMonitor->name, $content);
        $this->assertStringContainsString('token=REDACTED', $content);
        $this->assertStringNotContainsString('private-token', $content);
    }

    /** @param array<string, mixed> $attributes */
    private function createMonitor(array $attributes = []): Monitor
    {
        $url = 'https://example.com/monitors/'.Str::uuid();

        return Monitor::factory()->create([
            ...$attributes,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
    }
}
