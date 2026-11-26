<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationOutbox;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_slack_webhook_is_queued_separately_with_encrypted_destination_and_idempotency(): void
    {
        $webhookUrl = 'https://hooks.slack.com/services/T123/SECRET/WEBHOOK';
        Config::set('monitoring.notify_email', 'global@example.test');
        Config::set('monitoring.notify_slack_webhook', $webhookUrl);
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = Monitor::factory()->create();
        $run = CheckRun::factory()->for($monitor)->create();
        $incident = Incident::factory()->for($monitor)->create();

        $dispatcher = app(NotificationDispatcher::class);
        $dispatcher->queue($incident, $monitor, $run, 'opened');
        $dispatcher->queue($incident, $monitor, $run, 'opened');

        $emailOutbox = NotificationOutbox::query()->where('channel', 'email')->sole();
        $slackOutbox = NotificationOutbox::query()->where('channel', 'slack')->sole();

        $this->assertSame('global@example.test', $emailOutbox->recipient);
        $this->assertSame($webhookUrl, $slackOutbox->recipient);
        $this->assertNotSame($webhookUrl, DB::table('notification_outbox')->where('id', $slackOutbox->id)->value('recipient'));
        $this->assertArrayNotHasKey('recipient', $slackOutbox->toArray());
        $this->assertDatabaseCount('notification_outbox', 2);
        Queue::assertPushed(SendNotificationOutbox::class, 2);
    }

    public function test_slack_only_configuration_queues_incident_notifications_without_email(): void
    {
        Config::set('monitoring.notify_email', null);
        Config::set('monitoring.notify_slack_webhook', 'https://hooks.slack.com/services/T123/SECRET/WEBHOOK');
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = Monitor::factory()->create();
        $run = CheckRun::factory()->for($monitor)->create();
        $incident = Incident::factory()->for($monitor)->create();

        app(NotificationDispatcher::class)->queue($incident, $monitor, $run, 'opened');

        $outbox = NotificationOutbox::query()->sole();
        $this->assertSame(NotificationOutbox::CHANNEL_SLACK, $outbox->channel);
        $this->assertDatabaseHas('notification_outbox', ['id' => $outbox->id, 'status' => 'pending']);
        Queue::assertPushed(SendNotificationOutbox::class, 1);
    }

    public function test_slack_outbox_posts_expected_message_and_records_success(): void
    {
        $webhookUrl = 'https://hooks.slack.com/services/T123/SECRET/WEBHOOK';
        Http::fake([$webhookUrl => Http::response('', 200)]);
        $outbox = NotificationOutbox::factory()->create([
            'channel' => NotificationOutbox::CHANNEL_SLACK,
            'recipient' => $webhookUrl,
            'subject' => '[Monitor] Campaign is down',
            'body' => 'HTTP status: 503',
        ]);

        (new SendNotificationOutbox($outbox->id))->handle();

        $this->assertDatabaseHas('notification_outbox', [
            'id' => $outbox->id,
            'channel' => NotificationOutbox::CHANNEL_SLACK,
            'status' => 'sent',
            'attempts' => 1,
        ]);
        $this->assertNotNull($outbox->fresh()->sent_at);
        Http::assertSent(fn (Request $request): bool => $request->url() === $webhookUrl
            && $request['text'] === "[Monitor] Campaign is down\n\nHTTP status: 503"
        );
    }

    public function test_slack_failure_records_unknown_status_without_leaking_webhook_url(): void
    {
        $webhookUrl = 'https://hooks.slack.com/services/T123/SECRET/WEBHOOK';
        Http::fake([$webhookUrl => Http::response('invalid webhook', 500)]);
        $outbox = NotificationOutbox::factory()->create([
            'channel' => NotificationOutbox::CHANNEL_SLACK,
            'recipient' => $webhookUrl,
        ]);

        (new SendNotificationOutbox($outbox->id))->handle();

        $this->assertDatabaseHas('notification_outbox', [
            'id' => $outbox->id,
            'status' => 'unknown',
            'attempts' => 1,
        ]);
        $this->assertStringNotContainsString($webhookUrl, (string) $outbox->fresh()->last_error);
        $this->assertStringNotContainsString('SECRET', (string) $outbox->fresh()->last_error);
        Http::assertSent(fn (Request $request): bool => $request->url() === $webhookUrl);
    }

    public function test_database_queue_worker_processes_notification_failure_and_manual_retry_to_success(): void
    {
        $webhookUrl = 'https://hooks.slack.com/services/T123/SECRET/WEBHOOK';
        config(['queue.default' => 'database']);
        Http::fake([
            $webhookUrl => Http::sequence()
                ->push('temporarily unavailable', 503)
                ->push('', 200),
        ]);
        $user = User::factory()->create();
        $this->actingAs($user);
        $outbox = NotificationOutbox::factory()->create([
            'incident_id' => null,
            'event_type' => 'dns_daily_summary',
            'channel' => NotificationOutbox::CHANNEL_SLACK,
            'recipient' => $webhookUrl,
            'status' => 'pending',
        ]);

        Queue::connection('database')->push(new SendNotificationOutbox($outbox->id));
        $this->assertDatabaseCount('jobs', 1);

        $this->artisan('queue:work database --once --sleep=0 --tries=1')->assertExitCode(0);

        $this->assertDatabaseHas('notification_outbox', [
            'id' => $outbox->id,
            'status' => 'unknown',
            'attempts' => 1,
        ]);
        $this->assertDatabaseCount('jobs', 0);

        $this->from(route('notifications.index'))
            ->post(route('notifications.retry', $outbox), ['acknowledge_duplicate_risk' => '1'])
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('status');
        $this->assertDatabaseHas('notification_outbox', [
            'id' => $outbox->id,
            'status' => 'pending',
            'attempts' => 1,
        ]);
        $this->assertDatabaseCount('jobs', 1);

        $this->artisan('queue:work database --once --sleep=0 --tries=1')->assertExitCode(0);

        $this->assertDatabaseHas('notification_outbox', [
            'id' => $outbox->id,
            'status' => 'sent',
            'attempts' => 2,
            'last_error' => null,
        ]);
        $this->assertNotNull($outbox->fresh()->sent_at);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertSentCount(2);
    }

    public function test_insecure_slack_webhook_is_not_called_and_error_does_not_reveal_secret(): void
    {
        $webhookUrl = 'http://hooks.slack.com/services/T123/SECRET/WEBHOOK';
        $outbox = NotificationOutbox::factory()->create([
            'channel' => NotificationOutbox::CHANNEL_SLACK,
            'recipient' => $webhookUrl,
        ]);

        (new SendNotificationOutbox($outbox->id))->handle();

        $this->assertDatabaseHas('notification_outbox', [
            'id' => $outbox->id,
            'status' => 'unknown',
            'attempts' => 1,
        ]);
        $this->assertStringNotContainsString($webhookUrl, (string) $outbox->fresh()->last_error);
        $this->assertStringNotContainsString('SECRET', (string) $outbox->fresh()->last_error);
        Http::assertNothingSent();
    }
}
