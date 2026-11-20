<?php

namespace Tests\Feature;

use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_lists_active_and_upcoming_maintenance_in_order(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC'));
        $activeMonitor = $this->createMonitor('Active deployment');
        $activeStart = CarbonImmutable::parse('2026-11-19 19:30', 'Asia/Taipei');
        MaintenanceWindow::factory()->for($activeMonitor)->create([
            'starts_at' => $activeStart->utc(),
            'ends_at' => $activeStart->addHour()->utc(),
            'reason' => 'Active maintenance reason',
        ]);

        $weeklyMonitor = $this->createMonitor('Weekly deployment');
        $weeklyStart = CarbonImmutable::parse('2026-11-19 21:00', 'Asia/Taipei');
        MaintenanceWindow::factory()->for($weeklyMonitor)->create([
            'starts_at' => $weeklyStart->utc(),
            'ends_at' => $weeklyStart->addHour()->utc(),
            'repeat_weekly' => true,
            'repeat_until' => '2026-12-03',
            'reason' => 'Weekly maintenance reason',
        ]);

        $ongoingWeeklyMonitor = $this->createMonitor('Ongoing weekly maintenance');
        $ongoingWeeklyStart = CarbonImmutable::parse('2026-11-18 16:00', 'Asia/Taipei');
        MaintenanceWindow::factory()->for($ongoingWeeklyMonitor)->create([
            'starts_at' => $ongoingWeeklyStart->utc(),
            'ends_at' => $ongoingWeeklyStart->addDays(6)->utc(),
            'repeat_weekly' => true,
            'repeat_until' => '2026-11-18',
            'reason' => 'Last weekly occurrence is still active',
        ]);

        $upcomingMonitor = $this->createMonitor('Upcoming deployment');
        $upcomingStart = CarbonImmutable::parse('2026-11-20 09:00', 'Asia/Taipei');
        MaintenanceWindow::factory()->for($upcomingMonitor)->create([
            'starts_at' => $upcomingStart->utc(),
            'ends_at' => $upcomingStart->addHour()->utc(),
            'reason' => 'Upcoming maintenance reason',
        ]);

        $pastStart = CarbonImmutable::parse('2026-11-19 18:00', 'Asia/Taipei');
        MaintenanceWindow::factory()->for($activeMonitor)->create([
            'starts_at' => $pastStart->utc(),
            'ends_at' => $pastStart->addHour()->utc(),
            'reason' => 'Expired maintenance reason',
        ]);

        $distantStart = CarbonImmutable::parse('2026-12-21 10:00', 'Asia/Taipei');
        MaintenanceWindow::factory()->for($upcomingMonitor)->create([
            'starts_at' => $distantStart->utc(),
            'ends_at' => $distantStart->addHour()->utc(),
            'reason' => 'Distant maintenance reason',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('近期預定維護')
            ->assertSee('Active maintenance reason')
            ->assertSee('Weekly maintenance reason')
            ->assertSee('Last weekly occurrence is still active')
            ->assertSee('Upcoming maintenance reason')
            ->assertSee(route('monitors.show', $activeMonitor), false)
            ->assertDontSee('Expired maintenance reason')
            ->assertDontSee('Distant maintenance reason');

        $maintenanceSummary = $response->viewData('maintenanceSummary');

        $this->assertSame(6, $maintenanceSummary['count']);
        $this->assertSame([
            ['monitor_name' => 'Ongoing weekly maintenance', 'state' => 'active', 'starts_at' => '2026-11-18 16:00'],
            ['monitor_name' => 'Active deployment', 'state' => 'active', 'starts_at' => '2026-11-19 19:30'],
            ['monitor_name' => 'Weekly deployment', 'state' => 'upcoming', 'starts_at' => '2026-11-19 21:00'],
            ['monitor_name' => 'Upcoming deployment', 'state' => 'upcoming', 'starts_at' => '2026-11-20 09:00'],
            ['monitor_name' => 'Weekly deployment', 'state' => 'upcoming', 'starts_at' => '2026-11-26 21:00'],
            ['monitor_name' => 'Weekly deployment', 'state' => 'upcoming', 'starts_at' => '2026-12-03 21:00'],
        ], collect($maintenanceSummary['items'])->map(static fn (array $item): array => [
            'monitor_name' => $item['monitor_name'],
            'state' => $item['state'],
            'starts_at' => $item['starts_at']->format('Y-m-d H:i'),
        ])->all());
    }

    public function test_dashboard_shows_the_nearest_ten_maintenance_occurrences_after_chunking(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC'));

        for ($monitorNumber = 1; $monitorNumber <= 8; $monitorNumber++) {
            $this->createMonitor(sprintf('A monitor %03d', $monitorNumber));
        }

        $monitor = $this->createMonitor('Z scheduled monitor');
        $startsAt = CarbonImmutable::parse('2026-11-20 09:00', 'Asia/Taipei');
        MaintenanceWindow::factory()->count(501)->for($monitor)->create([
            'starts_at' => $startsAt->utc(),
            'ends_at' => $startsAt->addHour()->utc(),
            'reason' => 'Routine maintenance',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Z scheduled monitor')
            ->assertSee('近 30 天共有 501 段');

        $maintenanceSummary = $response->viewData('maintenanceSummary');

        $this->assertSame(501, $maintenanceSummary['count']);
        $this->assertCount(10, $maintenanceSummary['items']);
    }

    public function test_dashboard_explains_when_no_maintenance_is_scheduled(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('未來 30 天沒有預定維護時段。');

        $this->assertSame(0, $response->viewData('maintenanceSummary')['count']);
        $this->assertCount(0, $response->viewData('maintenanceSummary')['items']);
    }

    private function createMonitor(string $name): Monitor
    {
        $url = 'https://'.str()->slug($name).'.example.test/';

        return Monitor::factory()->create([
            'name' => $name,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
    }
}
