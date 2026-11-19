<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorIncidentFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_list_can_filter_current_open_and_suspect_incidents(): void
    {
        $user = User::factory()->create();
        $openMonitor = $this->createMonitor(['name' => 'Open campaign', 'config_version' => 2]);
        Incident::factory()->for($openMonitor)->create([
            'config_version' => 2,
            'state' => Incident::STATE_OPEN,
        ]);

        $suspectMonitor = $this->createMonitor(['name' => 'Suspect campaign']);
        Incident::factory()->for($suspectMonitor)->create([
            'state' => Incident::STATE_SUSPECT,
        ]);

        $pausedMonitor = $this->createMonitor(['name' => 'Paused with incident', 'enabled' => false]);
        Incident::factory()->for($pausedMonitor)->create();

        $oldConfigurationMonitor = $this->createMonitor(['name' => 'Old configuration incident', 'config_version' => 2]);
        Incident::factory()->for($oldConfigurationMonitor)->create(['config_version' => 1]);

        $resolvedMonitor = $this->createMonitor(['name' => 'Resolved incident']);
        Incident::factory()->for($resolvedMonitor)->create(['state' => Incident::STATE_RESOLVED]);

        $this->createMonitor(['name' => 'No incident']);

        $response = $this->actingAs($user)
            ->get(route('monitors.index', ['state' => 'incidents']))
            ->assertOk()
            ->assertSee('Open campaign')
            ->assertSee('Suspect campaign')
            ->assertSee('Paused with incident')
            ->assertDontSee('Old configuration incident')
            ->assertDontSee('Resolved incident')
            ->assertDontSee('No incident');

        $this->assertSame(3, $response->viewData('monitors')->total());
    }

    public function test_monitor_incident_filter_combines_with_search(): void
    {
        $user = User::factory()->create();
        $campaignMonitor = $this->createMonitor(['name' => 'Spring campaign']);
        Incident::factory()->for($campaignMonitor)->create();

        $otherMonitor = $this->createMonitor(['name' => 'Autumn campaign']);
        Incident::factory()->for($otherMonitor)->create();

        $response = $this->actingAs($user)
            ->get(route('monitors.index', ['search' => 'Spring', 'state' => 'incidents']))
            ->assertOk()
            ->assertSee('Spring campaign')
            ->assertDontSee('Autumn campaign');

        $this->assertSame(['search' => 'Spring', 'label' => '', 'state' => 'incidents', 'sort' => 'name'], $response->viewData('filters'));
        $this->assertSame(1, $response->viewData('monitors')->total());
    }

    public function test_monitor_incident_filter_and_search_are_preserved_in_pagination(): void
    {
        for ($index = 1; $index <= 51; $index++) {
            $monitor = $this->createMonitor(['name' => 'Campaign '.$index]);
            Incident::factory()->for($monitor)->create();
        }

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.index', ['search' => 'Campaign', 'state' => 'incidents']))
            ->assertOk()
            ->assertSee(route('monitors.index', [
                'search' => 'Campaign',
                'state' => 'incidents',
                'page' => 2,
            ]));

        $this->assertSame(51, $response->viewData('monitors')->total());
        $this->assertSame(['search' => 'Campaign', 'label' => '', 'state' => 'incidents', 'sort' => 'name'], $response->viewData('filters'));
    }

    public function test_monitor_list_rejects_unknown_status_filter(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('monitors.index'))
            ->get(route('monitors.index', ['state' => 'unknown']))
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHasErrors([
                'state' => '請選擇有效的監測狀態篩選條件。',
            ]);
    }

    /** @param array{name: string, config_version?: int, enabled?: bool} $attributes */
    private function createMonitor(array $attributes): Monitor
    {
        $url = 'https://example.com/monitors/'.hash('sha256', $attributes['name']);

        return Monitor::factory()->create([
            ...$attributes,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
    }
}
