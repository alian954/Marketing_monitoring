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

class MonitorDiscordNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_discord_webhook_is_encrypted_and_routes_incident_and_test_messages(): void
    {
        Config::set('monitoring.transport', 'fixture');
        Config::set('monitoring.notify_email', null);
        Config::set('monitoring.notify_slack_webhook', null);
        Config::set('monitoring.notify_discord_webhook', 'https://discord.com/api/webhooks/456/GLOBALTOKEN');
        Queue::fake([SendNotificationOutbox::class]);
        $webhookUrl = 'https://discord.com/api/webhooks/123/MONITORTOKEN';

        $response = $this->actingAs(User::factory()->create())
            ->post(route('monitors.store'), $this->monitorPayload($webhookUrl));

        $monitor = Monitor::query()->sole();
        $response->assertRedirect(route('monitors.show', $monitor));
        $this->assertSame($webhookUrl, $monitor->notification_discord_webhook);
        $this->assertNotSame($webhookUrl, DB::table('monitors')->where('id', $monitor->id)->value('notification_discord_webhook'));
        $this->assertArrayNotHasKey('notification_discord_webhook', $monitor->toArray());

        $this->get(route('monitors.edit', $monitor))
            ->assertOk()
            ->assertSee('此監測已設定專用 Discord webhook')
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
        $this->assertTrue($outboxes->every(static fn (NotificationOutbox $outbox): bool => $outbox->channel === NotificationOutbox::CHANNEL_DISCORD
            && $outbox->recipient === $webhookUrl));
        $this->assertNotSame($webhookUrl, DB::table('notification_outbox')->value('recipient'));
        $this->assertArrayNotHasKey('recipient', $outboxes->first()->toArray());
        Queue::assertPushed(SendNotificationOutbox::class, 2);

        $this->get(route('notifications.index', ['channel' => 'discord']))
            ->assertOk()
            ->assertSee('Discord webhook')
            ->assertSee('Webhook 端點已遮蔽')
            ->assertDontSee($webhookUrl);
        $csv = $this->get(route('notifications.export', ['channel' => 'discord']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('discord', $csv);
        $this->assertStringNotContainsString($webhookUrl, $csv);
    }

    public function test_blank_discord_webhook_is_preserved_and_can_be_cleared_to_use_the_global_hook(): void
    {
        Config::set('monitoring.transport', 'fixture');
        Config::set('monitoring.notify_email', null);
        Config::set('monitoring.notify_discord_webhook', 'https://discord.com/api/webhooks/456/GLOBALTOKEN');
        Queue::fake([SendNotificationOutbox::class]);
        $monitorWebhookUrl = 'https://discord.com/api/webhooks/123/MONITORTOKEN';
        $monitor = Monitor::factory()->create(['notification_discord_webhook' => $monitorWebhookUrl]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('monitors.update', $monitor), [
                'name' => $monitor->name,
                'url' => '',
                'notification_discord_webhook' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($monitorWebhookUrl, $monitor->fresh()->notification_discord_webhook);

        $this->put(route('monitors.update', $monitor), [
            'name' => $monitor->name,
            'url' => '',
            'notification_discord_webhook' => '',
            'clear_notification_discord_webhook' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertNull($monitor->fresh()->notification_discord_webhook);

        $monitor = $monitor->fresh();
        $run = CheckRun::factory()->for($monitor)->create();
        $incident = Incident::factory()->for($monitor)->create();
        app(NotificationDispatcher::class)->queue($incident, $monitor, $run, 'opened');
        $outbox = NotificationOutbox::query()->sole();
        $this->assertSame('https://discord.com/api/webhooks/456/GLOBALTOKEN', $outbox->recipient);

        $this->post(route('monitors.notifications.test', $monitor))
            ->assertRedirect(route('notifications.index', [
                'event_type' => NotificationOutbox::EVENT_TYPE_TEST,
                'monitor_id' => $monitor->id,
            ]));
        $this->assertSame(2, NotificationOutbox::query()->count());
    }

    public function test_discord_webhook_validation_rejects_unsafe_urls_without_flashing_secrets(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $webhookUrls = [
            'http://discord.com/api/webhooks/123/SECRET',
            'https://discord.com.attacker.test/api/webhooks/123/SECRET',
            'https://127.0.0.1/api/webhooks/123/SECRET',
            'https://user@discord.com/api/webhooks/123/SECRET',
            'https://discord.com:443/api/webhooks/123/SECRET',
            'https://discord.com/api/webhooks/123/SECRET?token=SECRET',
            'https://discord.com/api/webhooks/123/SECRET#fragment',
            'https://discord.com/api/webhooks/123/SECRET/extra',
            'https://discord.com/',
        ];

        $this->actingAs(User::factory()->create());

        foreach ($webhookUrls as $webhookUrl) {
            $this->from(route('monitors.create'))
                ->post(route('monitors.store'), $this->monitorPayload($webhookUrl))
                ->assertRedirect(route('monitors.create'))
                ->assertSessionHasErrors('notification_discord_webhook');

            $this->assertDatabaseCount('monitors', 0);
            $this->assertArrayNotHasKey('notification_discord_webhook', session()->get('_old_input', []));
        }
    }

    public function test_discord_outbox_waits_for_delivery_and_sends_bounded_content_without_mentions(): void
    {
        $webhookUrl = 'https://discord.com/api/webhooks/123/SECRET';
        Http::fake([$webhookUrl.'?wait=true' => Http::response(['id' => 'message-id'], 200)]);
        $outbox = NotificationOutbox::factory()->create([
            'channel' => NotificationOutbox::CHANNEL_DISCORD,
            'recipient' => $webhookUrl,
            'subject' => '[Monitor] @everyone incident',
            'body' => str_repeat('內容', 1200),
        ]);

        (new SendNotificationOutbox($outbox->id))->handle();

        $this->assertDatabaseHas('notification_outbox', [
            'id' => $outbox->id,
            'channel' => NotificationOutbox::CHANNEL_DISCORD,
            'status' => 'sent',
            'attempts' => 1,
        ]);
        Http::assertSent(fn (Request $request): bool => $request->url() === $webhookUrl.'?wait=true'
            && mb_strlen($request['content']) <= 2000
            && str_contains($request['content'], '@everyone')
            && $request['allowed_mentions'] === ['parse' => []]
        );
    }

    public function test_second_stage_escalation_does_not_repeat_the_primary_discord_webhook(): void
    {
        Config::set('monitoring.notify_email', null);
        $monitor = Monitor::factory()->create([
            'notification_discord_webhook' => 'https://discord.com/api/webhooks/123/MONITORTOKEN',
        ]);
        $run = CheckRun::factory()->for($monitor)->create();
        $incident = Incident::factory()->for($monitor)->create();

        app(NotificationDispatcher::class)->queue(
            $incident,
            $monitor,
            $run,
            NotificationOutbox::EVENT_TYPE_ESCALATION_STAGE_2,
            emailRecipientsOverride: ['manager@example.test'],
            includeWebhookChannels: false,
        );

        $this->assertSame(['email'], NotificationOutbox::query()->pluck('channel')->all());
        $this->assertSame('manager@example.test', NotificationOutbox::query()->sole()->recipient);
    }

    public function test_discord_http_failures_use_unknown_status_without_leaking_webhook_url(): void
    {
        $webhookUrl = 'https://discord.com/api/webhooks/123/SECRET';
        Http::fake([$webhookUrl.'?wait=true' => Http::response('server error', 500)]);
        $outbox = NotificationOutbox::factory()->create([
            'channel' => NotificationOutbox::CHANNEL_DISCORD,
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
        Http::assertSent(fn (Request $request): bool => $request->url() === $webhookUrl.'?wait=true');
    }

    public function test_invalid_discord_outbox_destination_is_not_called_and_does_not_leak_secret(): void
    {
        $webhookUrl = 'https://discord.com.attacker.test/api/webhooks/123/SECRET';
        $outbox = NotificationOutbox::factory()->create([
            'channel' => NotificationOutbox::CHANNEL_DISCORD,
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

    /** @return array{name: string, url: string, notification_discord_webhook: string, expected_text_mode: string} */
    private function monitorPayload(string $webhookUrl): array
    {
        return [
            'name' => 'Fixture service',
            'url' => 'https://fixture.test/ok',
            'notification_discord_webhook' => $webhookUrl,
            'expected_text_mode' => 'present',
        ];
    }
}
