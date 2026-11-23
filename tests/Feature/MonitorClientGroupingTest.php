<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MonitorClientGroupingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_and_monitor_list_filter_to_the_selected_client(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $firstClient = $user->clients()->create(['name' => 'Client Alpha']);
        $secondClient = $user->clients()->create(['name' => 'Client Beta']);
        $firstMonitor = Monitor::factory()->create([
            'name' => 'Alpha Website',
            'client_id' => $firstClient->id,
            'url' => 'https://alpha.example.test/',
            'url_hash' => Monitor::fingerprintUrl('https://alpha.example.test/'),
        ]);
        Monitor::factory()->create([
            'name' => 'Beta Website',
            'client_id' => $secondClient->id,
            'url' => 'https://beta.example.test/',
            'url_hash' => Monitor::fingerprintUrl('https://beta.example.test/'),
        ]);

        $dashboardResponse = $this->get(route('dashboard', ['client_id' => $firstClient->id]))
            ->assertOk()
            ->assertSee('Alpha Website')
            ->assertDontSee('Beta Website');

        $this->assertSame($firstClient->id, $dashboardResponse->viewData('selectedClientId'));
        $this->assertSame([$firstMonitor->id], $dashboardResponse->viewData('monitors')->pluck('id')->all());

        $this->get(route('monitors.index', ['client_id' => $secondClient->id]))
            ->assertOk()
            ->assertSee('Beta Website')
            ->assertDontSee('Alpha Website');

        $csv = $this->get(route('monitors.export', ['client_id' => $firstClient->id]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Alpha Website', $csv);
        $this->assertStringNotContainsString('Beta Website', $csv);
    }

    public function test_new_monitor_can_be_assigned_to_a_client_and_foreign_client_is_rejected(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $client = $user->clients()->create(['name' => 'Brand Account']);
        $foreignClient = $otherUser->clients()->create(['name' => 'Foreign Account']);

        $this->actingAs($user)
            ->get(route('monitors.create', ['client_id' => $client->id]))
            ->assertOk()
            ->assertViewHas('selectedClientId', $client->id);

        $this->post(route('monitors.store'), [
            'name' => 'Brand Landing Page',
            'url' => 'http://fixture.test/brand',
            'client_id' => $client->id,
        ])->assertRedirect();

        $monitor = Monitor::query()->where('name', 'Brand Landing Page')->sole();
        $this->assertSame($client->id, $monitor->client_id);

        $this->from(route('monitors.create'))
            ->post(route('monitors.store'), [
                'name' => 'Invalid Assignment',
                'url' => 'http://fixture.test/invalid',
                'client_id' => $foreignClient->id,
            ])
            ->assertSessionHasErrors(['client_id' => '所選客戶不存在或無權限。']);

        $this->assertDatabaseMissing('monitors', ['name' => 'Invalid Assignment']);
    }
}
