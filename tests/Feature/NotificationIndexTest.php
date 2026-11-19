<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationOutbox;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_history_shows_event_and_dns_digest_without_exposing_message_details(): void
    {
        $monitor = Monitor::factory()->create(['name' => 'Campaign landing page']);
        $incident = Incident::factory()->create(['monitor_id' => $monitor->id]);
        NotificationOutbox::factory()->create([
            'incident_id' => $incident->id,
            'event_type' => 'opened',
            'status' => 'failed',
            'recipient' => 'private-recipient@example.test',
            'body' => 'private message body',
        ]);
        NotificationOutbox::factory()->create([
            'incident_id' => null,
            'event_type' => 'dns_daily_summary',
            'status' => 'sent',
            'recipient' => 'dns-recipient@example.test',
            'body' => 'private DNS message body',
        ]);
        NotificationOutbox::factory()->create([
            'incident_id' => null,
            'event_type' => 'dns_daily_summary',
            'status' => 'pending',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('通知紀錄')
            ->assertSee('Campaign landing page')
            ->assertSee('異常通知')
            ->assertSee('電子郵件')
            ->assertSee('目的地')
            ->assertSee('p***t@example.test')
            ->assertSee('d***t@example.test')
            ->assertSee('每日 DNS 變更摘要')
            ->assertSee('排隊中')
            ->assertSee('我了解重試可能會重複傳送通知。')
            ->assertSee('重試傳送')
            ->assertDontSee('private-recipient@example.test')
            ->assertDontSee('dns-recipient@example.test')
            ->assertDontSee('private message body')
            ->assertDontSee('private DNS message body');
    }

    public function test_notification_history_filters_by_status_type_and_channel_and_keeps_filters_in_pagination(): void
    {
        for ($index = 0; $index < 26; $index++) {
            NotificationOutbox::factory()->create([
                'incident_id' => null,
                'event_type' => 'dns_daily_summary',
                'channel' => 'slack',
                'status' => 'failed',
            ]);
        }

        NotificationOutbox::factory()->create([
            'incident_id' => null,
            'event_type' => 'dns_daily_summary',
            'channel' => 'slack',
            'status' => 'sent',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('notifications.index', ['status' => 'failed', 'event_type' => 'dns_daily_summary', 'channel' => 'slack']))
            ->assertOk()
            ->assertSee('status=failed')
            ->assertSee('event_type=dns_daily_summary')
            ->assertSee('channel=slack');

        $this->assertSame(26, $response->viewData('notifications')->total());
        $response->assertSee(route('notifications.index', [
            'status' => 'failed',
            'event_type' => 'dns_daily_summary',
            'channel' => 'slack',
            'page' => 2,
        ]));
        $response->assertSee(route('notifications.export', [
            'status' => 'failed',
            'event_type' => 'dns_daily_summary',
            'channel' => 'slack',
        ]));
    }

    public function test_notification_history_can_filter_incident_and_test_notices_by_monitor(): void
    {
        $monitorUrl = 'https://example.test/target-campaign';
        $otherMonitorUrl = 'https://example.test/other-campaign';
        $monitor = Monitor::factory()->create([
            'name' => 'Target campaign',
            'url' => $monitorUrl,
            'url_hash' => Monitor::fingerprintUrl($monitorUrl),
        ]);
        $otherMonitor = Monitor::factory()->create([
            'name' => 'Other campaign',
            'url' => $otherMonitorUrl,
            'url_hash' => Monitor::fingerprintUrl($otherMonitorUrl),
        ]);
        $incident = Incident::factory()->for($monitor)->create();
        $otherIncident = Incident::factory()->for($otherMonitor)->create();
        NotificationOutbox::factory()->create(['incident_id' => $incident->id, 'event_type' => 'opened']);
        NotificationOutbox::factory()->create(['incident_id' => $otherIncident->id, 'event_type' => 'opened']);
        NotificationOutbox::factory()->create([
            'incident_id' => null,
            'monitor_id' => $monitor->id,
            'event_type' => NotificationOutbox::EVENT_TYPE_TEST,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('notifications.index', ['monitor_id' => $monitor->id]))
            ->assertOk()
            ->assertSee('2 筆符合條件的通知')
            ->assertSee('Target campaign');

        $notifications = $response->viewData('notifications');
        $this->assertSame(2, $notifications->total());
        $this->assertSame(
            ['Target campaign', 'Target campaign'],
            $notifications->getCollection()
                ->map(static fn (NotificationOutbox $notification): string => $notification->monitor?->name ?? $notification->incident?->monitor?->name ?? '')
                ->all(),
        );
    }

    public function test_filtered_notification_export_streams_safe_audit_fields_without_message_or_recipient(): void
    {
        $monitor = Monitor::factory()->create(['name' => '=HYPERLINK("https://evil.example")']);
        $incident = Incident::factory()->create(['monitor_id' => $monitor->id]);
        NotificationOutbox::factory()->create([
            'incident_id' => $incident->id,
            'event_type' => 'opened',
            'status' => 'failed',
            'recipient' => 'private-recipient@example.test',
            'body' => 'private message body',
        ]);
        NotificationOutbox::factory()->create([
            'incident_id' => null,
            'event_type' => 'dns_daily_summary',
            'status' => 'sent',
            'body' => 'filtered-out message body',
        ]);

        for ($index = 0; $index < 27; $index++) {
            NotificationOutbox::factory()->create([
                'incident_id' => $incident->id,
                'event_type' => 'opened',
                'status' => 'failed',
            ]);
        }

        $response = $this->actingAs(User::factory()->create())
            ->get(route('notifications.export', ['status' => 'failed', 'event_type' => 'opened']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('event_type,monitor_name,channel,status,attempts,created_at_utc,sent_at_utc', $content);
        $this->assertStringContainsString("'=HYPERLINK", $content);
        $this->assertStringContainsString('異常通知', $content);
        $this->assertStringNotContainsString('private-recipient@example.test', $content);
        $this->assertStringNotContainsString('private message body', $content);
        $this->assertStringNotContainsString('filtered-out message body', $content);
        $this->assertStringNotContainsString('dns_daily_summary', $content);
        $this->assertCount(29, explode("\n", trim($content)));
    }

    public function test_notification_history_filters_and_exports_slack_channel_without_exposing_webhook(): void
    {
        $webhookUrl = 'https://hooks.slack.com/services/T123/SECRET/WEBHOOK';
        NotificationOutbox::factory()->create([
            'incident_id' => null,
            'channel' => 'slack',
            'recipient' => $webhookUrl,
            'event_type' => 'dns_daily_summary',
            'status' => 'sent',
        ]);
        NotificationOutbox::factory()->create([
            'incident_id' => null,
            'channel' => 'email',
            'event_type' => 'dns_daily_summary',
            'status' => 'sent',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('notifications.index', ['channel' => 'slack']))
            ->assertOk()
            ->assertSee('Slack webhook')
            ->assertSee('Webhook 端點已遮蔽')
            ->assertDontSee($webhookUrl);

        $this->assertSame(1, $response->viewData('notifications')->total());

        $content = $this->get(route('notifications.export', ['channel' => 'slack']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('event_type,monitor_name,channel,status,attempts,created_at_utc,sent_at_utc', $content);
        $this->assertStringContainsString('slack', $content);
        $this->assertStringNotContainsString($webhookUrl, $content);
        $this->assertStringNotContainsString('SECRET', $content);
    }

    public function test_retry_requires_duplicate_risk_acknowledgment_then_queues_failed_notification(): void
    {
        Queue::fake([SendNotificationOutbox::class]);
        $outbox = NotificationOutbox::factory()->create([
            'incident_id' => null,
            'event_type' => 'dns_daily_summary',
            'channel' => 'slack',
            'recipient' => 'https://hooks.slack.com/services/T123/SECRET/WEBHOOK',
            'status' => 'failed',
            'last_error' => 'temporary delivery failure',
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('notifications.index'))
            ->post(route('notifications.retry', $outbox))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHasErrors('acknowledge_duplicate_risk');

        $this->assertDatabaseHas('notification_outbox', [
            'id' => $outbox->id,
            'status' => 'failed',
            'last_error' => 'temporary delivery failure',
        ]);
        Queue::assertNothingPushed();

        $this->post(route('notifications.retry', $outbox), ['acknowledge_duplicate_risk' => '1'])
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('notification_outbox', [
            'id' => $outbox->id,
            'channel' => 'slack',
            'status' => 'pending',
            'last_error' => null,
        ]);
        Queue::assertPushed(SendNotificationOutbox::class, fn (SendNotificationOutbox $job): bool => $job->outboxId === $outbox->id);
    }

    public function test_pending_notification_cannot_be_retried(): void
    {
        Queue::fake([SendNotificationOutbox::class]);
        $outbox = NotificationOutbox::factory()->create(['status' => 'pending']);

        $this->actingAs(User::factory()->create())
            ->post(route('notifications.retry', $outbox), ['acknowledge_duplicate_risk' => '1'])
            ->assertSessionHasErrors('notification');

        $this->assertDatabaseHas('notification_outbox', ['id' => $outbox->id, 'status' => 'pending']);
        Queue::assertNothingPushed();
    }
}
