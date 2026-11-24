<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MonitorEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_a_monitor_supersedes_old_incidents_and_keeps_them_in_history(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $user = User::factory()->create();
        $url = 'http://fixture.test/original';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'last_checked_at' => now(),
        ]);
        $incident = Incident::factory()->for($monitor)->create([
            'config_version' => 1,
            'state' => Incident::STATE_OPEN,
            'active_key' => hash('sha256', 'active-incident'),
            'opened_at' => now(),
        ]);
        IncidentEvent::factory()->create([
            'incident_id' => $incident->id,
            'check_run_id' => null,
            'event_type' => 'opened',
        ]);

        $this->actingAs($user)
            ->put(route('monitors.update', $monitor), [
                'name' => 'Updated fixture',
                'url' => 'http://healthy.fixture.test/updated',
                'label' => 'QA',
                'expected_text' => '',
                'redirect_hosts' => '',
            ])
            ->assertRedirect(route('monitors.show', $monitor));

        $this->assertDatabaseHas('monitors', ['id' => $monitor->id, 'config_version' => 2]);
        $this->assertDatabaseHas('incidents', [
            'id' => $incident->id,
            'config_version' => 1,
            'state' => Incident::STATE_SUPERSEDED,
            'active_key' => null,
            'resolved_at' => null,
        ]);
        $this->assertDatabaseHas('incident_events', [
            'incident_id' => $incident->id,
            'event_type' => 'superseded',
        ]);
        $this->assertDatabaseCount('notification_outbox', 0);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('目前沒有進行中的事件');
        $this->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('設定已變更')
            ->assertSee('舊事件已因設定變更而結束');
    }

    public function test_new_monitor_normalizes_and_deduplicates_redirect_hosts(): void
    {
        Config::set('monitoring.transport', 'fixture');

        $this->actingAs(User::factory()->create())
            ->post(route('monitors.store'), [
                'name' => 'Redirect monitoring',
                'url' => 'https://fixture.test/ok',
                'label' => 'Operations',
                'redirect_hosts' => " CDN.Example.com,cdn.example.com\nmedia.example.com ",
            ])
            ->assertRedirect();

        $this->assertSame(['cdn.example.com', 'media.example.com'], Monitor::query()->sole()->allowed_redirect_hosts);
    }

    public function test_new_monitor_normalizes_and_deduplicates_multiple_tags(): void
    {
        Config::set('monitoring.transport', 'fixture');

        $this->actingAs(User::factory()->create())
            ->post(route('monitors.store'), [
                'name' => 'Tagged website',
                'url' => 'https://fixture.test/tagged',
                'label' => " Operations ; Production, operations\n ",
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $monitor = Monitor::query()->sole();

        $this->assertSame(['Operations', 'Production'], $monitor->tags);
        $this->assertSame('Operations', $monitor->label);
        $this->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('Operations, Production');
    }

    public function test_duplicate_url_on_create_links_to_existing_monitor_without_creating_another(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $user = User::factory()->create();
        $this->actingAs($user);
        $url = 'https://fixture.test/duplicate';
        $existingMonitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);

        $this->from(route('monitors.create'))
            ->post(route('monitors.store'), [
                'name' => 'Duplicate monitor',
                'url' => $url,
                'label' => '',
                'redirect_hosts' => '',
            ])
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors(['url' => '此網址已在監測中。'])
            ->assertSessionHas('duplicateMonitorId', $existingMonitor->getKey())
            ->assertSessionHas('_old_input.url', $url);

        $this->get(route('monitors.create'))
            ->assertOk()
            ->assertSee('查看既有監測項目')
            ->assertSee(route('monitors.show', $existingMonitor), false)
            ->assertSee($url);

        $this->assertDatabaseCount('monitors', 1);
    }

    public function test_duplicate_url_on_update_links_to_existing_monitor_and_preserves_current_settings(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $user = User::factory()->create();
        $this->actingAs($user);
        $currentUrl = 'https://fixture.test/current';
        $duplicateUrl = 'https://fixture.test/duplicate';
        $currentMonitor = Monitor::factory()->create([
            'name' => 'Current monitor',
            'url' => $currentUrl,
            'url_hash' => Monitor::fingerprintUrl($currentUrl),
        ]);
        $existingMonitor = Monitor::factory()->create([
            'url' => $duplicateUrl,
            'url_hash' => Monitor::fingerprintUrl($duplicateUrl),
        ]);

        $this->from(route('monitors.edit', $currentMonitor))
            ->put(route('monitors.update', $currentMonitor), [
                'name' => 'Changed name',
                'url' => $duplicateUrl,
                'label' => '',
                'redirect_hosts' => '',
            ])
            ->assertRedirect(route('monitors.edit', $currentMonitor))
            ->assertSessionHasErrors(['url' => '此網址已在監測中。'])
            ->assertSessionHas('duplicateMonitorId', $existingMonitor->getKey());

        $this->get(route('monitors.edit', $currentMonitor))
            ->assertOk()
            ->assertSee('查看既有監測項目')
            ->assertSee(route('monitors.show', $existingMonitor), false);

        $this->assertSame($currentUrl, $currentMonitor->fresh()->url);
        $this->assertSame('Current monitor', $currentMonitor->fresh()->name);
        $this->assertDatabaseCount('monitors', 2);
    }

    public function test_unrelated_monitor_updates_preserve_all_tags(): void
    {
        $monitor = Monitor::factory()->create([
            'label' => 'Operations, Production',
        ]);

        $monitor->forceFill(['last_checked_at' => now()])->save();

        $this->assertSame(['Operations', 'Production'], $monitor->fresh()->tags);
        $this->assertSame('Operations', $monitor->fresh()->label);
    }

    public function test_monitor_tag_input_rejects_more_than_ten_tags_and_tags_over_one_hundred_characters(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $user = User::factory()->create();
        $basePayload = [
            'name' => 'Tagged website',
            'url' => 'https://fixture.test/tagged',
        ];

        $this->actingAs($user)
            ->from(route('monitors.create'))
            ->post(route('monitors.store'), [
                ...$basePayload,
                'label' => implode(', ', range(1, 11)),
            ])
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors([
                'label' => '每個監測項目最多可設定 10 個標籤。',
            ]);

        $this->from(route('monitors.create'))
            ->post(route('monitors.store'), [
                ...$basePayload,
                'label' => str_repeat('a', 101),
            ])
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors([
                'label' => '每個標籤不得超過 100 個字元。',
            ]);

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_monitor_detail_paginates_all_retained_check_runs(): void
    {
        $monitor = Monitor::factory()->create();
        $user = User::factory()->create();
        $oldestRun = null;

        for ($index = 0; $index < 51; $index++) {
            $run = CheckRun::factory()->for($monitor)->create([
                'scheduled_at' => now()->subMinutes($index),
            ]);

            if ($index === 50) {
                $oldestRun = $run;
            }
        }

        $firstPage = $this->actingAs($user)
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('展開紀錄可查看遮罩後的最終網址與重新導向路徑');

        $firstPageRuns = $firstPage->viewData('runs');
        $this->assertSame(51, $firstPageRuns->total());
        $this->assertCount(50, $firstPageRuns->getCollection());
        $this->assertFalse($firstPageRuns->getCollection()->contains('id', $oldestRun->id));
        $firstPage->assertSee(route('monitors.show', ['monitor' => $monitor, 'page' => 2]));

        $secondPage = $this->get(route('monitors.show', ['monitor' => $monitor, 'page' => 2]))
            ->assertOk();

        $secondPageRuns = $secondPage->viewData('runs');
        $this->assertSame(51, $secondPageRuns->total());
        $this->assertCount(1, $secondPageRuns->getCollection());
        $this->assertTrue($secondPageRuns->getCollection()->contains('id', $oldestRun->id));
    }
}
