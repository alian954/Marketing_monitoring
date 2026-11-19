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
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonitorSlackNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_slack_webhook_is_encrypted_and_routes_test_and_incident_messages(): void
    {
        Config::set('monitoring.transport', 'fixture');
        Config::set('monitoring.notify_slack_webhook', 'https://hooks.slack.com/services/T123/GLOBAL/SECRET');
        Queue::fake([SendNotificationOutbox::class]);
        $webhookUrl = 'https://hooks.slack-gov.com/services/T456/MONITOR/SECRET';

        $response = $this->actingAs(User::factory()->create())
            ->post(route('monitors.store'), [
                'name' => 'Fixture service',
                'url' => 'https://fixture.test/ok',
                'notification_slack_webhook' => $webhookUrl,
                'expected_text_mode' => 'present',
            ]);

        $monitor = Monitor::query()->sole();
        $response->assertRedirect(route('monitors.show', $monitor));
        $this->assertSame($webhookUrl, $monitor->notification_slack_webhook);
        $this->assertNotSame($webhookUrl, DB::table('monitors')->where('id', $monitor->id)->value('notification_slack_webhook'));
        $this->assertArrayNotHasKey('notification_slack_webhook', $monitor->toArray());

        $this->get(route('monitors.edit', $monitor))
            ->assertOk()
            ->assertSee('此監測已設定專用 Slack webhook')
            ->assertDontSee($webhookUrl)
            ->assertSee('type="password"', false)
            ->assertSee('data-secret-toggle', false);

        $run = CheckRun::factory()->for($monitor)->create();
        $incident = Incident::factory()->for($monitor)->create();
        app(NotificationDispatcher::class)->queue($incident, $monitor, $run, 'opened');

        $this->post(route('monitors.notifications.test', $monitor))
            ->assertRedirect(route('notifications.index', [
                'event_type' => NotificationOutbox::EVENT_TYPE_TEST,
                'monitor_id' => $monitor->id,
            ]));

        $outboxes = NotificationOutbox::query()->orderBy('id')->get();
        $this->assertCount(2, $outboxes);
        $this->assertTrue($outboxes->every(static fn (NotificationOutbox $outbox): bool => $outbox->channel === NotificationOutbox::CHANNEL_SLACK
            && $outbox->recipient === $webhookUrl));
        $this->assertNotSame($webhookUrl, DB::table('notification_outbox')->value('recipient'));
        Queue::assertPushed(SendNotificationOutbox::class, 2);

        Http::fake([$webhookUrl => Http::response('', 200)]);
        (new SendNotificationOutbox($outboxes->first()->id))->handle();

        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === $webhookUrl);
        $this->assertDatabaseHas('notification_outbox', ['id' => $outboxes->first()->id, 'status' => 'sent']);
    }

    public function test_blank_monitor_webhook_is_preserved_and_can_be_cleared_to_use_the_global_hook(): void
    {
        Config::set('monitoring.transport', 'fixture');
        Config::set('monitoring.notify_slack_webhook', 'https://hooks.slack.com/services/T123/GLOBAL/SECRET');
        $monitorWebhookUrl = 'https://hooks.slack.com/services/T123/MONITOR/SECRET';
        $monitor = Monitor::factory()->create(['notification_slack_webhook' => $monitorWebhookUrl]);

        $this->actingAs(User::factory()->create())
            ->put(route('monitors.update', $monitor), [
                'name' => $monitor->name,
                'url' => '',
                'notification_slack_webhook' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($monitorWebhookUrl, $monitor->fresh()->notification_slack_webhook);

        $this->put(route('monitors.update', $monitor), [
            'name' => $monitor->name,
            'url' => '',
            'notification_slack_webhook' => '',
            'clear_notification_slack_webhook' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertNull($monitor->fresh()->notification_slack_webhook);

        $monitor = $monitor->fresh();
        $run = CheckRun::factory()->for($monitor)->create();
        $incident = Incident::factory()->for($monitor)->create();
        app(NotificationDispatcher::class)->queue($incident, $monitor, $run, 'opened');

        $outbox = NotificationOutbox::query()->sole();
        $this->assertSame('https://hooks.slack.com/services/T123/GLOBAL/SECRET', $outbox->recipient);
    }

    public function test_monitor_slack_webhook_rejects_unsafe_destinations_without_flashing_the_secret(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $webhookUrls = [
            'http://hooks.slack.com/services/T123/SECRET',
            'https://hooks.slack.com.attacker.test/services/T123/SECRET',
            'https://127.0.0.1/services/T123/SECRET',
            'https://user@hooks.slack.com/services/T123/SECRET',
            'https://hooks.slack.com:443/services/T123/SECRET',
            'https://hooks.slack.com/services/T123/SECRET?token=SECRET',
            'https://hooks.slack.com/services/T123/SECRET#secret',
            'https://hooks.slack.com/',
        ];

        $this->actingAs(User::factory()->create());

        foreach ($webhookUrls as $webhookUrl) {
            $this->from(route('monitors.create'))
                ->post(route('monitors.store'), [
                    'name' => 'Fixture service',
                    'url' => 'https://fixture.test/ok',
                    'notification_slack_webhook' => $webhookUrl,
                    'expected_text_mode' => 'present',
                ])
                ->assertRedirect(route('monitors.create'))
                ->assertSessionHasErrors('notification_slack_webhook');

            $this->assertDatabaseCount('monitors', 0);
            $this->assertArrayNotHasKey('notification_slack_webhook', session()->get('_old_input', []));
        }

    }
}
