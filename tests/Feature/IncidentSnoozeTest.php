<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationOutbox;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\IncidentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IncidentSnoozeTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_incident_notifications_can_be_snoozed_and_resumed_with_audit_events(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 08:00:00', 'UTC'));
        $operator = User::factory()->create(['name' => 'On-call operator']);
        $monitor = $this->monitor(1);
        $incident = Incident::factory()->for($monitor)->create(['state' => Incident::STATE_OPEN]);

        $this->actingAs($operator)
            ->from(route('monitors.show', $monitor))
            ->post(route('incidents.notifications.snooze', $incident), [
                'duration_minutes' => [$incident->id => 240],
            ])
            ->assertRedirect(route('monitors.show', $monitor))
            ->assertSessionHas('status', '提醒與升級通知已暫停至 2026/11/19 20:00。');

        $incident->refresh();
        $this->assertSame('2026-11-19 12:00:00', $incident->snoozed_until?->toDateTimeString());
        $snoozeEvent = IncidentEvent::query()
            ->where('incident_id', $incident->id)
            ->where('event_type', 'notifications_snoozed')
            ->sole();
        $this->assertSame('On-call operator', $snoozeEvent->evidence['actor_name']);
        $this->assertSame(240, $snoozeEvent->evidence['duration_minutes']);

        $this->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('提醒與升級目前暫停至')
            ->assertSee('立即恢復通知')
            ->assertSee('重新設定暫停時間');

        $this->delete(route('incidents.notifications.snooze.destroy', $incident))
            ->assertRedirect(route('monitors.show', $monitor))
            ->assertSessionHas('status', '事件提醒與升級通知已恢復。');

        $this->assertNull($incident->fresh()->snoozed_until);
        $this->assertSame(
            ['notifications_snoozed', 'notifications_resumed'],
            IncidentEvent::query()->where('incident_id', $incident->id)->orderBy('id')->pluck('event_type')->all(),
        );
        $this->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('事件提醒與升級通知已恢復')
            ->assertDontSee('立即恢復通知');
    }

    public function test_snoozing_requires_authentication_an_allowed_duration_and_an_open_incident(): void
    {
        $monitor = $this->monitor(2);
        $openIncident = Incident::factory()->for($monitor)->create(['state' => Incident::STATE_OPEN]);

        $this->post(route('incidents.notifications.snooze', $openIncident), [
            'duration_minutes' => [$openIncident->id => 60],
        ])->assertRedirect(route('login'));

        $operator = User::factory()->create();
        $this->actingAs($operator)
            ->from(route('monitors.show', $monitor))
            ->post(route('incidents.notifications.snooze', $openIncident), [
                'duration_minutes' => [$openIncident->id => 90],
            ])
            ->assertRedirect(route('monitors.show', $monitor))
            ->assertSessionHasErrors('duration_minutes.'.$openIncident->id);

        $suspectIncident = Incident::factory()->for($monitor)->create(['state' => Incident::STATE_SUSPECT]);
        $this->from(route('monitors.show', $monitor))
            ->post(route('incidents.notifications.snooze', $suspectIncident), [
                'duration_minutes' => [$suspectIncident->id => 60],
            ])
            ->assertRedirect(route('monitors.show', $monitor))
            ->assertSessionHasErrors('incident');

        $this->assertNull($openIncident->fresh()->snoozed_until);
        $this->assertNull($suspectIncident->fresh()->snoozed_until);
        $this->assertDatabaseCount('incident_events', 0);
    }

    public function test_snoozed_incidents_do_not_queue_reminders_or_escalations_until_the_snooze_expires(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        Config::set('monitoring.incident_reminder_interval_minutes', 60);
        Config::set('monitoring.notify_email', 'ops@example.test');
        Queue::fake([SendNotificationOutbox::class]);

        $monitor = $this->monitor(3, [
            'notification_escalation_email' => 'lead@example.test',
            'notification_escalation_delay_minutes' => 60,
            'notification_escalation_stage_2_email' => 'manager@example.test',
            'notification_escalation_stage_2_delay_minutes' => 120,
        ]);
        $incident = $this->incidentWithFailure($monitor, now()->subHours(3), [
            'snoozed_until' => now()->addHour(),
        ]);
        $manager = app(IncidentManager::class);

        $this->assertSame(0, $manager->queueDueReminders());
        $this->assertSame(0, $manager->queueDueEscalations());
        $this->assertDatabaseCount('notification_outbox', 0);

        $this->travelTo(Carbon::parse('2026-11-19 13:00:00', 'UTC'));

        $this->assertSame(1, $manager->queueDueReminders());
        $this->assertSame(2, $manager->queueDueEscalations());
        $this->assertSame(
            ['reminder', 'escalation', 'escalation_stage_2'],
            NotificationOutbox::query()->where('incident_id', $incident->id)->orderBy('id')->pluck('event_type')->all(),
        );
        $this->assertSame(3, IncidentEvent::query()->where('incident_id', $incident->id)->whereIn('event_type', [
            'reminder_queued', 'escalation_queued',
        ])->count());
        Queue::assertPushed(SendNotificationOutbox::class, 3);
    }

    public function test_queued_snoozable_notifications_remain_pending_and_are_dispatched_after_expiry(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        Queue::fake([SendNotificationOutbox::class]);
        Mail::fake();
        $monitor = $this->monitor(4);
        $incident = Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_OPEN,
            'snoozed_until' => now()->addHour(),
        ]);
        $reminder = NotificationOutbox::factory()->for($incident)->create([
            'event_type' => NotificationOutbox::EVENT_TYPE_REMINDER,
        ]);
        $escalation = NotificationOutbox::factory()->for($incident)->create([
            'event_type' => NotificationOutbox::EVENT_TYPE_ESCALATION,
        ]);
        $stageTwo = NotificationOutbox::factory()->for($incident)->create([
            'event_type' => NotificationOutbox::EVENT_TYPE_ESCALATION_STAGE_2,
        ]);
        $initialAlert = NotificationOutbox::factory()->for($incident)->create(['event_type' => 'opened']);

        $this->artisan('monitors:dispatch-due')->assertExitCode(0);
        Queue::assertPushed(SendNotificationOutbox::class, 1);
        Queue::assertPushed(
            SendNotificationOutbox::class,
            fn (SendNotificationOutbox $job): bool => $job->outboxId === $initialAlert->id,
        );

        foreach ([$reminder, $escalation, $stageTwo] as $outbox) {
            (new SendNotificationOutbox($outbox->id))->handle();
            $this->assertDatabaseHas('notification_outbox', [
                'id' => $outbox->id,
                'status' => 'pending',
                'attempts' => 0,
            ]);
        }

        (new SendNotificationOutbox($initialAlert->id))->handle();
        $this->assertDatabaseHas('notification_outbox', ['id' => $initialAlert->id, 'status' => 'sent']);

        $this->travelTo(Carbon::parse('2026-11-19 13:00:00', 'UTC'));
        $this->artisan('monitors:dispatch-due')->assertExitCode(0);
        Queue::assertPushed(SendNotificationOutbox::class, 4);

        foreach ([$reminder, $escalation, $stageTwo] as $outbox) {
            (new SendNotificationOutbox($outbox->id))->handle();
            $this->assertDatabaseHas('notification_outbox', [
                'id' => $outbox->id,
                'status' => 'sent',
                'attempts' => 1,
            ]);
        }
    }

    public function test_resuming_a_snooze_early_releases_pending_notifications_on_the_next_scheduler_run(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        Queue::fake([SendNotificationOutbox::class]);
        Mail::fake();
        $operator = User::factory()->create();
        $monitor = $this->monitor(5);
        $incident = Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_OPEN,
            'snoozed_until' => now()->addHour(),
        ]);
        $reminder = NotificationOutbox::factory()->for($incident)->create([
            'event_type' => NotificationOutbox::EVENT_TYPE_REMINDER,
        ]);

        (new SendNotificationOutbox($reminder->id))->handle();
        $this->assertDatabaseHas('notification_outbox', ['id' => $reminder->id, 'status' => 'pending']);

        $this->actingAs($operator)
            ->delete(route('incidents.notifications.snooze.destroy', $incident))
            ->assertRedirect();

        $this->artisan('monitors:dispatch-due')->assertExitCode(0);
        Queue::assertPushed(
            SendNotificationOutbox::class,
            fn (SendNotificationOutbox $job): bool => $job->outboxId === $reminder->id,
        );

        (new SendNotificationOutbox($reminder->id))->handle();
        $this->assertDatabaseHas('notification_outbox', [
            'id' => $reminder->id,
            'status' => 'sent',
            'attempts' => 1,
        ]);
    }

    public function test_pending_snoozed_reminders_are_cancelled_if_the_incident_resolves(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        $monitor = $this->monitor(6);
        $incident = Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_RESOLVED,
            'snoozed_until' => now()->addHour(),
            'resolved_at' => now(),
        ]);
        $reminder = NotificationOutbox::factory()->for($incident)->create([
            'event_type' => NotificationOutbox::EVENT_TYPE_REMINDER,
        ]);

        (new SendNotificationOutbox($reminder->id))->handle();
        $this->assertDatabaseHas('notification_outbox', [
            'id' => $reminder->id,
            'status' => 'pending',
            'attempts' => 0,
        ]);

        $this->travelTo(Carbon::parse('2026-11-19 13:00:00', 'UTC'));
        (new SendNotificationOutbox($reminder->id))->handle();

        $this->assertDatabaseHas('notification_outbox', [
            'id' => $reminder->id,
            'status' => 'cancelled',
            'attempts' => 0,
        ]);
    }

    public function test_bulk_snoozing_audits_open_incidents_and_skips_ineligible_or_already_snoozed_incidents(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 08:00:00', 'UTC'));
        $operator = User::factory()->create(['name' => 'On-call operator']);
        $monitor = $this->monitor(8);
        $openIncident = Incident::factory()->for($monitor)->create(['state' => Incident::STATE_OPEN]);
        $alreadySnoozedIncident = Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_OPEN,
            'snoozed_until' => now()->addHour(),
        ]);
        $suspectIncident = Incident::factory()->for($monitor)->create(['state' => Incident::STATE_SUSPECT]);
        $resolvedIncident = Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_RESOLVED,
            'resolved_at' => now(),
        ]);

        $this->actingAs($operator)
            ->post(route('incidents.bulk-acknowledge', ['severity' => 'warning']), [
                'acknowledgement_action' => 'snooze_notifications',
                'duration_minutes' => 240,
                'incident_ids' => [$openIncident->id, $alreadySnoozedIncident->id, $suspectIncident->id, $resolvedIncident->id],
            ])
            ->assertRedirect(route('incidents.index', ['severity' => 'warning']))
            ->assertSessionHas('status', '已暫停 1 件事件的提醒與升級通知至 2026/11/19 20:00；略過 3 件尚未成立、已有有效暫停或已恢復／結束的事件。');

        $this->assertSame('2026-11-19 12:00:00', $openIncident->fresh()->snoozed_until?->toDateTimeString());
        $this->assertSame('2026-11-19 09:00:00', $alreadySnoozedIncident->fresh()->snoozed_until?->toDateTimeString());
        $this->assertNull($suspectIncident->fresh()->snoozed_until);
        $this->assertNull($resolvedIncident->fresh()->snoozed_until);

        $snoozeEvent = IncidentEvent::query()->sole();
        $this->assertSame($openIncident->id, $snoozeEvent->incident_id);
        $this->assertSame('notifications_snoozed', $snoozeEvent->event_type);
        $this->assertSame($operator->id, $snoozeEvent->evidence['actor_id']);
        $this->assertSame('On-call operator', $snoozeEvent->evidence['actor_name']);
        $this->assertSame(240, $snoozeEvent->evidence['duration_minutes']);
        $this->assertSame('2026-11-19T12:00:00+00:00', $snoozeEvent->evidence['snoozed_until']);
        $this->assertDatabaseCount('notification_outbox', 0);
    }

    public function test_bulk_snoozing_requires_an_allowed_duration(): void
    {
        $operator = User::factory()->create();
        $monitor = $this->monitor(9);
        $incident = Incident::factory()->for($monitor)->create(['state' => Incident::STATE_OPEN]);

        foreach ([null, 90] as $duration) {
            $payload = [
                'acknowledgement_action' => 'snooze_notifications',
                'incident_ids' => [$incident->id],
            ];
            if ($duration !== null) {
                $payload['duration_minutes'] = $duration;
            }

            $this->actingAs($operator)
                ->from(route('incidents.index'))
                ->post(route('incidents.bulk-acknowledge'), $payload)
                ->assertRedirect(route('incidents.index'))
                ->assertSessionHasErrors('duration_minutes');
        }

        $this->assertNull($incident->fresh()->snoozed_until);
        $this->assertDatabaseCount('incident_events', 0);
    }

    public function test_incident_list_exposes_bulk_snooze_action_and_duration_options(): void
    {
        $monitor = $this->monitor(10);
        Incident::factory()->for($monitor)->create(['state' => Incident::STATE_OPEN]);

        $this->actingAs(User::factory()->create())
            ->get(route('incidents.index'))
            ->assertOk()
            ->assertSee('暫停所選事件的通知')
            ->assertSee('通知暫停時間')
            ->assertSee('name="duration_minutes"', false)
            ->assertSee('value="30"', false)
            ->assertSee('value="60"', false)
            ->assertSee('value="240"', false)
            ->assertSee('value="1440"', false);
    }

    /** @param array<string, mixed> $attributes */
    private function monitor(int $number, array $attributes = []): Monitor
    {
        $url = 'https://snooze-'.$number.'.example/health';

        return Monitor::factory()->create(array_merge([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'next_check_at' => now()->addHour(),
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function incidentWithFailure(Monitor $monitor, Carbon $openedAt, array $attributes = []): Incident
    {
        $run = CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => $openedAt,
            'finished_at' => $openedAt,
            'normal_status' => 503,
        ]);
        $incident = Incident::factory()->for($monitor)->create(array_merge([
            'config_version' => $monitor->config_version,
            'state' => Incident::STATE_OPEN,
            'opened_at' => $openedAt,
            'last_seen_at' => $openedAt,
            'resolved_at' => null,
        ], $attributes));

        IncidentEvent::factory()->for($incident)->for($run)->create([
            'event_type' => 'failure_seen',
            'evidence' => ['status' => 503],
            'created_at' => $openedAt,
        ]);

        return $incident;
    }
}
