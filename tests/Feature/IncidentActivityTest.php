<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_classification_and_note_changes_are_saved_to_the_incident_timeline(): void
    {
        $operator = User::factory()->create(['name' => 'Duty lead']);
        $monitor = Monitor::factory()->create();
        $incident = Incident::factory()->for($monitor)->create(['state' => Incident::STATE_OPEN]);

        $this->actingAs($operator)
            ->from(route('monitors.show', $monitor))
            ->patch(route('incidents.update', $incident), [
                'classification' => 'true_issue',
                'note' => 'Initial context',
            ])
            ->assertRedirect(route('monitors.show', $monitor));

        $this->patch(route('incidents.update', $incident), [
            'classification' => 'false_alarm',
            'note' => '<script>alert(1)</script>',
        ])->assertRedirect();

        $this->patch(route('incidents.update', $incident), [
            'classification' => 'false_alarm',
            'note' => '<script>alert(1)</script>',
        ])->assertRedirect();

        $this->patch(route('incidents.update', $incident), [
            'classification' => 'false_alarm',
            'note' => '',
        ])->assertRedirect();

        $this->assertSame('false_alarm', $incident->fresh()->classification);
        $this->assertNull($incident->fresh()->note);
        $events = IncidentEvent::query()->where('incident_id', $incident->id)->orderBy('id')->get();
        $this->assertSame([
            'classification_changed',
            'incident_note_added',
            'classification_changed',
            'incident_note_added',
            'incident_note_cleared',
        ], $events->pluck('event_type')->all());
        $this->assertSame('Duty lead', $events[0]->evidence['actor_name']);
        $this->assertSame('true_issue', $events[0]->evidence['to']);
        $this->assertSame('false_alarm', $events[2]->evidence['to']);
        $this->assertSame('Initial context', $events[1]->evidence['note']);
        $this->assertSame('<script>alert(1)</script>', $events[3]->evidence['note']);

        $this->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('事件分類已變更')
            ->assertSee('確認為真實異常 → 確認為誤報')
            ->assertSee('新增檢視備註')
            ->assertSee('Initial context')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('已清除檢視備註')
            ->assertSee('由 Duty lead');
    }

    public function test_unchanged_classification_and_note_do_not_create_duplicate_activity(): void
    {
        $operator = User::factory()->create();
        $monitor = Monitor::factory()->create();
        $incident = Incident::factory()->for($monitor)->create([
            'classification' => 'true_issue',
            'note' => 'Already recorded',
        ]);

        $this->actingAs($operator)
            ->patch(route('incidents.update', $incident), [
                'classification' => 'true_issue',
                'note' => 'Already recorded',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('incident_events', 0);
    }

    public function test_unauthenticated_users_cannot_add_incident_activity(): void
    {
        $monitor = Monitor::factory()->create();
        $incident = Incident::factory()->for($monitor)->create();

        $this->patch(route('incidents.update', $incident), [
            'classification' => 'true_issue',
            'note' => 'Private context',
        ])->assertRedirect(route('login'));

        $this->assertNull($incident->fresh()->classification);
        $this->assertNull($incident->fresh()->note);
        $this->assertDatabaseCount('incident_events', 0);
    }
}
