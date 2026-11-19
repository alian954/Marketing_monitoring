<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspect_and_open_incidents_can_be_acknowledged_without_being_resolved(): void
    {
        $operator = User::factory()->create(['name' => '<script>alert(1)</script>']);
        $incidents = collect([Incident::STATE_SUSPECT, Incident::STATE_OPEN])->map(function (string $state) use ($operator): array {
            $url = 'https://example.com/'.$state;
            $monitor = Monitor::factory()->create([
                'url' => $url,
                'url_hash' => Monitor::fingerprintUrl($url),
            ]);
            $incident = Incident::factory()->for($monitor)->create(['state' => $state]);

            $this->actingAs($operator)
                ->from(route('monitors.show', $monitor))
                ->post(route('incidents.acknowledgement.store', $incident))
                ->assertRedirect(route('monitors.show', $monitor));

            $incident->refresh();
            $this->assertSame($state, $incident->state);
            $this->assertSame($operator->id, $incident->acknowledged_by_id);
            $this->assertNotNull($incident->acknowledged_at);

            return [$monitor, $incident];
        });

        [$monitor, $incident] = $incidents->last();
        $this->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('解除認領')
            ->assertSee('事件已認領')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('認領只記錄接手人，不會自動結束事件或停止告警通知。');
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);

        $this->assertDatabaseCount('incident_events', 2);
        $this->assertDatabaseHas('incident_events', [
            'incident_id' => $incident->id,
            'event_type' => 'acknowledged',
        ]);
        $this->assertDatabaseCount('notification_outbox', 0);
    }

    public function test_acknowledgement_is_idempotent_and_another_operator_cannot_take_it_over(): void
    {
        $firstOperator = User::factory()->create(['name' => 'Primary operator']);
        $secondOperator = User::factory()->create(['name' => 'Second operator']);
        $monitor = Monitor::factory()->create();
        $incident = Incident::factory()->for($monitor)->create(['state' => Incident::STATE_OPEN]);

        $this->actingAs($firstOperator)
            ->from(route('monitors.show', $monitor))
            ->post(route('incidents.acknowledgement.store', $incident))
            ->assertRedirect(route('monitors.show', $monitor));
        $this->actingAs($secondOperator)
            ->from(route('monitors.show', $monitor))
            ->post(route('incidents.acknowledgement.store', $incident))
            ->assertRedirect(route('monitors.show', $monitor));

        $this->assertSame($firstOperator->id, $incident->fresh()->acknowledged_by_id);
        $this->assertDatabaseCount('incident_events', 1);
        $this->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('由 Primary operator 接手')
            ->assertDontSee('由 Second operator 接手');
    }

    public function test_acknowledgement_can_be_released_and_both_transitions_are_audited(): void
    {
        $operator = User::factory()->create(['name' => 'On-call operator']);
        $monitor = Monitor::factory()->create();
        $incident = Incident::factory()->for($monitor)->create(['state' => Incident::STATE_OPEN]);

        $this->actingAs($operator)
            ->from(route('monitors.show', $monitor))
            ->post(route('incidents.acknowledgement.store', $incident))
            ->assertRedirect(route('monitors.show', $monitor));
        $this->delete(route('incidents.acknowledgement.destroy', $incident))
            ->assertRedirect(route('monitors.show', $monitor));
        $this->delete(route('incidents.acknowledgement.destroy', $incident))
            ->assertRedirect(route('monitors.show', $monitor));

        $incident->refresh();
        $this->assertSame(Incident::STATE_OPEN, $incident->state);
        $this->assertNull($incident->acknowledged_at);
        $this->assertNull($incident->acknowledged_by_id);
        $this->assertSame(
            ['acknowledged', 'unacknowledged'],
            IncidentEvent::query()->where('incident_id', $incident->id)->orderBy('id')->pluck('event_type')->all(),
        );
        $acknowledgementEvent = IncidentEvent::query()
            ->where('incident_id', $incident->id)
            ->where('event_type', 'acknowledged')
            ->sole();
        $this->assertSame('On-call operator', $acknowledgementEvent->evidence['actor_name']);
        $this->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('認領處理')
            ->assertSee('事件認領已解除');
    }

    public function test_resolved_incidents_cannot_be_acknowledged_or_released(): void
    {
        $operator = User::factory()->create();
        $monitor = Monitor::factory()->create();
        $unacknowledgedIncident = Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_RESOLVED,
            'resolved_at' => now(),
        ]);
        $acknowledgedIncident = Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_RESOLVED,
            'resolved_at' => now(),
            'acknowledged_at' => now()->subMinute(),
            'acknowledged_by_id' => $operator->id,
        ]);

        $this->actingAs($operator)
            ->from(route('monitors.show', $monitor))
            ->post(route('incidents.acknowledgement.store', $unacknowledgedIncident))
            ->assertRedirect(route('monitors.show', $monitor))
            ->assertSessionHasErrors(['incident' => '只有進行中的事件可以認領或解除認領。']);
        $this->delete(route('incidents.acknowledgement.destroy', $acknowledgedIncident))
            ->assertRedirect(route('monitors.show', $monitor))
            ->assertSessionHasErrors(['incident' => '只有進行中的事件可以認領或解除認領。']);

        $this->assertNull($unacknowledgedIncident->fresh()->acknowledged_at);
        $this->assertSame($operator->id, $acknowledgedIncident->fresh()->acknowledged_by_id);
        $this->assertDatabaseCount('incident_events', 0);
    }

    public function test_unauthenticated_users_cannot_acknowledge_or_release_an_incident(): void
    {
        $monitor = Monitor::factory()->create();
        $incident = Incident::factory()->for($monitor)->create(['state' => Incident::STATE_OPEN]);

        $this->post(route('incidents.acknowledgement.store', $incident))
            ->assertRedirect(route('login'));
        $this->delete(route('incidents.acknowledgement.destroy', $incident))
            ->assertRedirect(route('login'));

        $this->assertNull($incident->fresh()->acknowledged_at);
        $this->assertDatabaseCount('incident_events', 0);
    }

    public function test_deleting_the_acknowledging_user_keeps_the_event_audit_and_incident(): void
    {
        $operator = User::factory()->create(['name' => 'Former operator']);
        $monitor = Monitor::factory()->create();
        $incident = Incident::factory()->for($monitor)->create(['state' => Incident::STATE_OPEN]);

        $this->actingAs($operator)
            ->from(route('monitors.show', $monitor))
            ->post(route('incidents.acknowledgement.store', $incident))
            ->assertRedirect(route('monitors.show', $monitor));
        $operator->delete();

        $incident->refresh();
        $this->assertSame(Incident::STATE_OPEN, $incident->state);
        $this->assertNull($incident->acknowledged_by_id);
        $this->assertNotNull($incident->acknowledged_at);
        $this->assertSame('Former operator', IncidentEvent::query()->sole()->evidence['actor_name']);
        $this->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('由 帳戶已刪除 接手')
            ->assertSee('Former operator');

    }

    public function test_incident_list_only_offers_bulk_acknowledgement_for_active_unacknowledged_events(): void
    {
        $monitor = Monitor::factory()->create();
        $eligibleIncident = Incident::factory()->for($monitor)->create(['state' => Incident::STATE_OPEN]);
        $operator = User::factory()->create();
        $acknowledgedIncident = Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_OPEN,
            'acknowledged_at' => now(),
            'acknowledged_by_id' => $operator->id,
        ]);
        $resolvedIncident = Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_RESOLVED,
            'resolved_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('incidents.index'))
            ->assertOk()
            ->assertSee('認領所選事件')
            ->assertSee('name="incident_ids[]" value="'.$eligibleIncident->id.'"', false)
            ->assertDontSee('name="incident_ids[]" value="'.$acknowledgedIncident->id.'"', false)
            ->assertDontSee('name="incident_ids[]" value="'.$resolvedIncident->id.'"', false);
    }

    public function test_bulk_acknowledgement_audits_active_events_and_skips_ineligible_events(): void
    {
        $actor = User::factory()->create(['name' => 'On-call operator']);
        $previousActor = User::factory()->create(['name' => 'Previous operator']);
        $monitor = Monitor::factory()->create();
        $suspectIncident = Incident::factory()->for($monitor)->create(['state' => Incident::STATE_SUSPECT]);
        $openIncident = Incident::factory()->for($monitor)->create(['state' => Incident::STATE_OPEN]);
        $acknowledgedIncident = Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_OPEN,
            'acknowledged_at' => now()->subMinute(),
            'acknowledged_by_id' => $previousActor->id,
        ]);
        $resolvedIncident = Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_RESOLVED,
            'resolved_at' => now(),
        ]);

        $this->actingAs($actor)
            ->post(route('incidents.bulk-acknowledge', ['severity' => 'warning']), [
                'incident_ids' => [$suspectIncident->id, $openIncident->id, $acknowledgedIncident->id, $resolvedIncident->id],
            ])
            ->assertRedirect(route('incidents.index', ['severity' => 'warning']))
            ->assertSessionHas('status', '已認領 2 件事件；略過 2 件已認領、已恢復或已結束的事件。');

        foreach ([$suspectIncident, $openIncident] as $incident) {
            $this->assertSame($actor->id, $incident->fresh()->acknowledged_by_id);
            $this->assertNotNull($incident->fresh()->acknowledged_at);
        }
        $this->assertSame($previousActor->id, $acknowledgedIncident->fresh()->acknowledged_by_id);
        $this->assertNull($resolvedIncident->fresh()->acknowledged_at);
        $this->assertDatabaseCount('incident_events', 2);
        $this->assertSame(['acknowledged', 'acknowledged'], IncidentEvent::query()->orderBy('id')->pluck('event_type')->all());

        foreach (IncidentEvent::query()->get() as $event) {
            $this->assertSame('On-call operator', $event->evidence['actor_name']);
        }
    }

    public function test_bulk_acknowledgement_requires_a_selection_and_authentication(): void
    {
        $this->post(route('incidents.bulk-acknowledge'))
            ->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->from(route('incidents.index'))
            ->post(route('incidents.bulk-acknowledge'))
            ->assertRedirect(route('incidents.index'))
            ->assertSessionHasErrors('incident_ids');

        $this->assertDatabaseCount('incident_events', 0);
    }
}
