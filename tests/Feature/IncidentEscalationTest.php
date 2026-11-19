<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationOutbox;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\IncidentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IncidentEscalationTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_form_exposes_optional_escalation_settings(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('monitors.create'))
            ->assertOk()
            ->assertSee('升級告警收件信箱')
            ->assertSee('升級等待時間（分鐘）')
            ->assertSee('事件持續未恢復且未被認領');
    }

    public function test_monitor_creation_encrypts_escalation_recipients_and_saves_delay(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $emailList = "backup@example.test\noncall@example.test";

        $this->actingAs(User::factory()->create())
            ->post(route('monitors.store'), [
                'name' => 'Campaign landing page',
                'url' => 'https://fixture.test/health',
                'notification_escalation_email' => $emailList,
                'notification_escalation_delay_minutes' => 30,
            ])
            ->assertSessionHasNoErrors();

        $monitor = Monitor::query()->sole();
        $storedEmail = DB::table('monitors')->where('id', $monitor->id)->value('notification_escalation_email');

        $this->assertSame($emailList, $monitor->notification_escalation_email);
        $this->assertSame(30, $monitor->notification_escalation_delay_minutes);
        $this->assertNotSame($emailList, $storedEmail);
        $this->assertArrayNotHasKey('notification_escalation_email', $monitor->toArray());
    }

    public function test_escalation_recipients_require_a_valid_delay_and_valid_email_addresses(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $user = User::factory()->create();
        $baseInput = ['name' => 'Escalation validation', 'url' => 'https://fixture.test/health'];

        $this->actingAs($user)
            ->from(route('monitors.create'))
            ->post(route('monitors.store'), $baseInput + [
                'notification_escalation_email' => 'backup@example.test',
            ])
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors('notification_escalation_delay_minutes');

        $this->from(route('monitors.create'))
            ->post(route('monitors.store'), $baseInput + [
                'notification_escalation_email' => 'not-an-email',
                'notification_escalation_delay_minutes' => 30,
            ])
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors('notification_escalation_email');

        foreach ([4, 1441] as $delayMinutes) {
            $this->from(route('monitors.create'))
                ->post(route('monitors.store'), $baseInput + [
                    'notification_escalation_email' => 'backup@example.test',
                    'notification_escalation_delay_minutes' => $delayMinutes,
                ])
                ->assertRedirect(route('monitors.create'))
                ->assertSessionHasErrors('notification_escalation_delay_minutes');
        }

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_monitor_edit_can_clear_the_entire_escalation_policy(): void
    {
        $monitor = $this->monitor(9);

        $this->actingAs(User::factory()->create())
            ->put(route('monitors.update', $monitor), [
                'name' => $monitor->name,
                'url' => '',
                'notification_escalation_email' => '',
                'notification_escalation_delay_minutes' => 30,
            ])
            ->assertRedirect(route('monitors.show', $monitor));

        $this->assertNull($monitor->fresh()->notification_escalation_email);
        $this->assertNull($monitor->fresh()->notification_escalation_delay_minutes);
    }

    public function test_scheduler_queues_one_escalation_to_secondary_recipients_after_the_delay(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
        $webhookUrl = 'https://hooks.slack.com/services/T123/SECRET/WEBHOOK';
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = $this->monitor(1, [
            'notification_email' => 'primary@example.test',
            'notification_escalation_email' => "backup@example.test\noncall@example.test",
            'notification_escalation_delay_minutes' => 30,
            'notification_slack_webhook' => $webhookUrl,
        ]);
        $incident = $this->openIncident($monitor, now()->subMinutes(31));

        $this->assertSame(1, app(IncidentManager::class)->queueDueEscalations());

        $outboxes = NotificationOutbox::query()
            ->where('event_type', NotificationOutbox::EVENT_TYPE_ESCALATION)
            ->orderBy('recipient')
            ->get();
        $this->assertCount(2, $outboxes);
        $this->assertSame(['backup@example.test', 'oncall@example.test'], $outboxes->pluck('recipient')->all());
        $this->assertTrue($outboxes->every(static fn (NotificationOutbox $outbox): bool => $outbox->channel === NotificationOutbox::CHANNEL_EMAIL
            && str_contains($outbox->subject, '已升級告警')
            && str_contains($outbox->body, 'HTTP 狀態碼：503')));
        $this->assertDatabaseHas('incident_events', [
            'incident_id' => $incident->id,
            'event_type' => 'escalation_queued',
        ]);
        Queue::assertPushed(SendNotificationOutbox::class, 2);

        $this->assertSame(0, app(IncidentManager::class)->queueDueEscalations());
        $this->assertDatabaseCount('notification_outbox', 2);

        $this->actingAs(User::factory()->create())
            ->get(route('notifications.index', ['event_type' => NotificationOutbox::EVENT_TYPE_ESCALATION]))
            ->assertOk()
            ->assertSee('事件升級通知')
            ->assertSee('b***p@example.test')
            ->assertDontSee($webhookUrl);

        $this->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('升級告警已排入通知')
            ->assertSee('升級告警通知');
    }

    public function test_scheduler_waits_until_the_deadline_and_skips_acknowledged_resolved_or_maintained_incidents(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
        Queue::fake([SendNotificationOutbox::class]);
        $dueMonitor = $this->monitor(2, ['notification_escalation_delay_minutes' => 30]);
        $dueIncident = $this->openIncident($dueMonitor, now()->subMinutes(31));

        $recentMonitor = $this->monitor(3, ['notification_escalation_delay_minutes' => 30]);
        $this->openIncident($recentMonitor, now()->subMinutes(29));

        $acknowledgedMonitor = $this->monitor(4, ['notification_escalation_delay_minutes' => 30]);
        $this->openIncident($acknowledgedMonitor, now()->subHours(2), ['acknowledged_at' => now()->subHour()]);

        $resolvedMonitor = $this->monitor(5, ['notification_escalation_delay_minutes' => 30]);
        $this->openIncident($resolvedMonitor, now()->subHours(2), [
            'state' => Incident::STATE_RESOLVED,
            'resolved_at' => now()->subHour(),
        ]);

        $maintainedMonitor = $this->monitor(6, ['notification_escalation_delay_minutes' => 30]);
        $this->openIncident($maintainedMonitor, now()->subHours(2));
        MaintenanceWindow::factory()->for($maintainedMonitor)->create([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->assertSame(1, app(IncidentManager::class)->queueDueEscalations());
        $this->assertDatabaseHas('notification_outbox', [
            'incident_id' => $dueIncident->id,
            'event_type' => NotificationOutbox::EVENT_TYPE_ESCALATION,
        ]);
        $this->assertDatabaseCount('notification_outbox', 1);
        Queue::assertPushed(SendNotificationOutbox::class, 1);
    }

    public function test_due_dispatch_command_runs_the_escalation_sweep(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = $this->monitor(10);
        $this->openIncident($monitor, now()->subHour());

        $this->artisan('monitors:dispatch-due')
            ->expectsOutput('已將 0 個到期監測檢查加入佇列，排入 0 個持續事件提醒與 1 個升級通知。')
            ->assertExitCode(0);

        Queue::assertPushed(SendNotificationOutbox::class, 1);
        $this->assertDatabaseCount('notification_outbox', 1);
    }

    public function test_queued_escalations_are_cancelled_if_the_incident_is_acknowledged_or_resolved_before_sending(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
        Queue::fake([SendNotificationOutbox::class]);
        $acknowledgedIncident = $this->openIncident(
            $this->monitor(7, ['notification_escalation_delay_minutes' => 5]),
            now()->subMinutes(10),
        );
        $resolvedIncident = $this->openIncident(
            $this->monitor(8, ['notification_escalation_delay_minutes' => 5]),
            now()->subMinutes(10),
        );

        $this->assertSame(2, app(IncidentManager::class)->queueDueEscalations());
        $outboxes = NotificationOutbox::query()
            ->where('event_type', NotificationOutbox::EVENT_TYPE_ESCALATION)
            ->orderBy('incident_id')
            ->get();
        $operator = User::factory()->create();
        $this->actingAs($operator)
            ->post(route('incidents.acknowledgement.store', $acknowledgedIncident))
            ->assertRedirect();
        $resolvedIncident->forceFill(['state' => Incident::STATE_RESOLVED, 'resolved_at' => now()])->save();

        Mail::fake();
        foreach ($outboxes as $outbox) {
            (new SendNotificationOutbox($outbox->id))->handle();
        }

        $this->assertSame(2, NotificationOutbox::query()
            ->where('event_type', NotificationOutbox::EVENT_TYPE_ESCALATION)
            ->where('status', 'cancelled')
            ->count());
        $this->assertSame(0, NotificationOutbox::query()
            ->where('event_type', NotificationOutbox::EVENT_TYPE_ESCALATION)
            ->sum('attempts'));
        Mail::assertOutgoingCount(0);
    }

    private function monitor(int $number, array $attributes = []): Monitor
    {
        $url = 'https://escalation-'.$number.'.example/health';

        return Monitor::factory()->create(array_merge([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'next_check_at' => now()->addHour(),
            'notification_email' => 'primary@example.test',
            'notification_escalation_email' => 'escalation@example.test',
            'notification_escalation_delay_minutes' => 30,
        ], $attributes));
    }

    private function openIncident(Monitor $monitor, Carbon $openedAt, array $attributes = []): Incident
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
            'rule_key' => 'http',
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
