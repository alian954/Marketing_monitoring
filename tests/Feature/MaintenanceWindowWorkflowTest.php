<?php

namespace Tests\Feature;

use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceWindowWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_maintenance_window_uses_taipei_time_and_can_be_cancelled(): void
    {
        $monitor = Monitor::factory()->create();
        $start = now('Asia/Taipei')->addHours(2)->startOfMinute();
        $end = $start->copy()->addHour();

        $this->actingAs(User::factory()->create())
            ->post(route('monitors.maintenance-windows.store', $monitor), [
                'starts_at' => $start->format('Y-m-d\\TH:i'),
                'ends_at' => $end->format('Y-m-d\\TH:i'),
                'reason' => '網站版本部署',
            ])
            ->assertRedirect(route('monitors.show', $monitor));

        $window = MaintenanceWindow::query()->sole();
        $this->assertSame($start->utc()->toDateTimeString(), $window->starts_at->utc()->toDateTimeString());
        $this->assertSame($end->utc()->toDateTimeString(), $window->ends_at->utc()->toDateTimeString());

        $this->delete(route('monitors.maintenance-windows.destroy', [$monitor, $window]))
            ->assertRedirect(route('monitors.show', $monitor));

        $this->assertDatabaseMissing('maintenance_windows', ['id' => $window->id]);
    }

    public function test_overlapping_maintenance_windows_are_rejected(): void
    {
        $monitor = Monitor::factory()->create();
        $start = now('Asia/Taipei')->addDay()->startOfHour();
        $window = MaintenanceWindow::factory()->for($monitor)->create([
            'starts_at' => $start->copy()->utc(),
            'ends_at' => $start->copy()->addHours(2)->utc(),
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.show', $monitor))
            ->post(route('monitors.maintenance-windows.store', $monitor), [
                'starts_at' => $start->copy()->addMinutes(30)->format('Y-m-d\\TH:i'),
                'ends_at' => $start->copy()->addHours(3)->format('Y-m-d\\TH:i'),
                'reason' => 'Overlapping window',
            ])
            ->assertRedirect(route('monitors.show', $monitor))
            ->assertSessionHasErrors('starts_at');

        $this->assertDatabaseCount('maintenance_windows', 1);
        $this->assertDatabaseHas('maintenance_windows', ['id' => $window->id]);
    }

    public function test_weekly_maintenance_repeats_at_the_same_taipei_time_until_its_final_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-19 00:00:00', 'UTC'));
        $monitor = Monitor::factory()->create();
        $startsAt = CarbonImmutable::parse('2026-11-20 22:00', 'Asia/Taipei');
        $endsAt = $startsAt->addHours(2);

        $this->actingAs(User::factory()->create())
            ->post(route('monitors.maintenance-windows.store', $monitor), [
                'starts_at' => $startsAt->format('Y-m-d\\TH:i'),
                'ends_at' => $endsAt->format('Y-m-d\\TH:i'),
                'reason' => '例行版本部署',
                'repeat_weekly' => '1',
                'repeat_until' => '2026-12-18',
            ])
            ->assertRedirect(route('monitors.show', $monitor));

        $window = MaintenanceWindow::query()->sole();
        $occurrences = $window->occurrenceIntervals();

        $this->assertTrue($window->repeat_weekly);
        $this->assertSame('2026-12-18', $window->repeat_until->format('Y-m-d'));
        $this->assertCount(5, $occurrences);
        $this->assertSame('2026-11-27 22:00', $occurrences[1]['starts_at']->format('Y-m-d H:i'));
        $this->assertTrue($window->isActiveAt(CarbonImmutable::parse('2026-12-04 23:00', 'Asia/Taipei')));
        $this->assertFalse($window->isActiveAt(CarbonImmutable::parse('2026-12-05 00:00', 'Asia/Taipei')));
        $this->assertTrue($window->isActiveAt(CarbonImmutable::parse('2026-12-18 22:30', 'Asia/Taipei')));
        $this->assertFalse($window->hasUpcomingOrActiveOccurrence(CarbonImmutable::parse('2026-12-19 00:00', 'Asia/Taipei')));

        $this->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('每週重複，截止 2026/12/18')
            ->assertSee('id="repeat_weekly"', false)
            ->assertSee('id="repeat_until"', false);
    }

    public function test_weekly_maintenance_rejects_overlap_in_a_later_occurrence(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-19 00:00:00', 'UTC'));
        $monitor = Monitor::factory()->create();
        $start = CarbonImmutable::parse('2026-11-20 22:00', 'Asia/Taipei');
        $window = MaintenanceWindow::factory()->for($monitor)->create([
            'starts_at' => $start->utc(),
            'ends_at' => $start->addHour()->utc(),
            'repeat_weekly' => true,
            'repeat_until' => '2026-12-18',
        ]);
        $laterOccurrence = CarbonImmutable::parse('2026-12-04 22:30', 'Asia/Taipei');

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.show', $monitor))
            ->post(route('monitors.maintenance-windows.store', $monitor), [
                'starts_at' => $laterOccurrence->format('Y-m-d\\TH:i'),
                'ends_at' => $laterOccurrence->addHour()->format('Y-m-d\\TH:i'),
                'reason' => '衝突維護',
            ])
            ->assertRedirect(route('monitors.show', $monitor))
            ->assertSessionHasErrors('starts_at');

        $this->from(route('monitors.show', $monitor))
            ->post(route('monitors.maintenance-windows.store', $monitor), [
                'starts_at' => $laterOccurrence->format('Y-m-d\\TH:i'),
                'ends_at' => $laterOccurrence->addHour()->format('Y-m-d\\TH:i'),
                'reason' => '衝突維護',
                'repeat_weekly' => '1',
                'repeat_until' => '2026-12-18',
            ])
            ->assertRedirect(route('monitors.show', $monitor))
            ->assertSessionHasErrors('starts_at');

        $this->assertDatabaseCount('maintenance_windows', 1);
        $this->assertDatabaseHas('maintenance_windows', ['id' => $window->id]);
    }

    public function test_weekly_maintenance_requires_a_final_date_and_rejects_ranges_over_one_year(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-19 00:00:00', 'UTC'));
        $monitor = Monitor::factory()->create();
        $startsAt = CarbonImmutable::parse('2026-11-20 22:00', 'Asia/Taipei');
        $request = [
            'starts_at' => $startsAt->format('Y-m-d\\TH:i'),
            'ends_at' => $startsAt->addHour()->format('Y-m-d\\TH:i'),
            'reason' => '例行部署',
            'repeat_weekly' => '1',
        ];

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.show', $monitor))
            ->post(route('monitors.maintenance-windows.store', $monitor), $request)
            ->assertRedirect(route('monitors.show', $monitor))
            ->assertSessionHasErrors('repeat_until');

        $this->from(route('monitors.show', $monitor))
            ->post(route('monitors.maintenance-windows.store', $monitor), array_merge($request, [
                'repeat_until' => '2026-11-19',
            ]))
            ->assertRedirect(route('monitors.show', $monitor))
            ->assertSessionHasErrors('repeat_until');

        $this->from(route('monitors.show', $monitor))
            ->post(route('monitors.maintenance-windows.store', $monitor), array_merge($request, [
                'repeat_until' => '2027-11-21',
            ]))
            ->assertRedirect(route('monitors.show', $monitor))
            ->assertSessionHasErrors('repeat_until');

        $this->assertDatabaseCount('maintenance_windows', 0);
    }

    public function test_weekly_maintenance_does_not_allow_a_single_occurrence_longer_than_seven_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-19 00:00:00', 'UTC'));
        $monitor = Monitor::factory()->create();
        $startsAt = CarbonImmutable::parse('2026-11-20 22:00', 'Asia/Taipei');

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.show', $monitor))
            ->post(route('monitors.maintenance-windows.store', $monitor), [
                'starts_at' => $startsAt->format('Y-m-d\\TH:i'),
                'ends_at' => $startsAt->addDays(7)->addMinute()->format('Y-m-d\\TH:i'),
                'reason' => '例行部署',
                'repeat_weekly' => '1',
                'repeat_until' => '2026-12-18',
            ])
            ->assertRedirect(route('monitors.show', $monitor))
            ->assertSessionHasErrors('ends_at');

        $this->assertDatabaseCount('maintenance_windows', 0);
    }
}
