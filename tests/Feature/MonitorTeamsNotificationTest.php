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

class MonitorTeamsNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_teams_webhook_is_encrypted_and_routes_incident_and_test_messages(): void
    {
        Config::set('monitoring.transport', 'fixture');
        Config::set('monitoring.notify_email', null);
        Config::set('monitoring.notify_slack_webhook', null);
        Config::set('monitoring.notify_discord_webhook', null);
        Config::set('monitoring.notify_teams_webhook', 'https://global.logic.azure.com:443/workflows/global?sig=GLOBALSECRET');
        Queue::fake([SendNotificationOutbox::class]);
        $webhookUrl = $this->teamsWebhookUrl();

        $response = $this->actingAs(User::factory()->create())
            ->post(route('monitors.store'), $this->monitorPayload($webhookUrl));

        $monitor = Monitor::query()->sole();
        $response->assertRedirect(route('monitors.show', $monitor));
        $this->assertSame($webhookUrl, $monitor->notification_teams_webhook);
        $this->assertNotSame($webhookUrl, DB::table('monitors')->where('id', $monitor->id)->value('notification_teams_webhook'));
        $this->assertArrayNotHasKey('notification_teams_webhook', $monitor->toArray());

        $this->get(route('monitors.edit', $monitor))
            ->assertOk()
            ->assertSee('Microsoft Teams Workflows Webhook')
            ->assertSee('此監測已設定專用 Teams Workflows webhook')
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
        $this->assertTrue($outboxes->every(static fn (NotificationOutbox $outbox): bool => $outbox->channel === NotificationOutbox::CHANNEL_TEAMS
            && $outbox->recipient === $webhookUrl));
        $this->assertNotSame($webhookUrl, DB::table('notification_outbox')->value('recipient'));
        $this->assertArrayNotHasKey('recipient', $outboxes->first()->toArray());
        Queue::assertPushed(SendNotificationOutbox::class, 2);

        $this->get(route('notifications.index', ['channel' => 'teams']))
            ->assertOk()
            ->assertSee('Microsoft Teams workflow')
            ->assertSee('Webhook 端點已遮蔽')
            ->assertDontSee($webhookUrl);
        $csv = $this->get(route('notifications.export', ['channel' => 'teams']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('teams', $csv);
        $this->assertStringNotContainsString($webhookUrl, $csv);
    }

    public function test_blank_teams_webhook_is_preserved_and_can_be_cleared_to_use_the_global_hook(): void
    {
        Config::set('monitoring.notify_email', null);
        Config::set('monitoring.notify_teams_webhook', 'https://global.environment.api.powerplatform.com/powerautomate/workflows/global?sig=GLOBALSECRET');
        Queue::fake([SendNotificationOutbox::class]);
        $monitorWebhookUrl = $this->teamsWebhookUrl();
        $monitor = Monitor::factory()->create(['notification_teams_webhook' => $monitorWebhookUrl]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('monitors.update', $monitor), [
                'name' => $monitor->name,
                'url' => '',
                'notification_teams_webhook' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($monitorWebhookUrl, $monitor->fresh()->notification_teams_webhook);

        $this->put(route('monitors.update', $monitor), [
            'name' => $monitor->name,
            'url' => '',
            'notification_teams_webhook' => '',
            'clear_notification_teams_webhook' => '1',
        ])->assertSessionHasNoErrors();

        $monitor = $monitor->fresh();
        $this->assertNull($monitor->notification_teams_webhook);
        $run = CheckRun::factory()->for($monitor)->create();
        $incident = Incident::factory()->for($monitor)->create();
        app(NotificationDispatcher::class)->queue($incident, $monitor, $run, 'opened');

        $this->assertSame(
            'https://global.environment.api.powerplatform.com/powerautomate/workflows/global?sig=GLOBALSECRET',
            NotificationOutbox::query()->sole()->recipient,
        );
    }

    public function test_global_teams_workflow_can_be_tested_from_the_notification_history(): void
    {
        $webhookUrl = $this->teamsWebhookUrl();
        Config::set('monitoring.notify_email', null);
        Config::set('monitoring.notify_slack_webhook', null);
        Config::set('monitoring.notify_discord_webhook', null);
        Config::set('monitoring.notify_teams_webhook', $webhookUrl);
        Queue::fake([SendNotificationOutbox::class]);

        $this->actingAs(User::factory()->create())
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Microsoft Teams workflow')
            ->assertSee('option value="teams"', false);

        $this->post(route('notifications.test'))
            ->assertRedirect(route('notifications.index', ['event_type' => NotificationOutbox::EVENT_TYPE_TEST]))
            ->assertSessionHas('status');

        $outbox = NotificationOutbox::query()->sole();
        $this->assertSame(NotificationOutbox::CHANNEL_TEAMS, $outbox->channel);
        $this->assertSame($webhookUrl, $outbox->recipient);
        Queue::assertPushed(SendNotificationOutbox::class, 1);
    }

    public function test_teams_webhook_validation_rejects_unsafe_urls_without_flashing_secrets(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $webhookUrls = [
            'http://tenant.logic.azure.com/workflows/123?sig=HTTPSECRET',
            'https://tenant.api.powerplatform.com.attacker.test/workflows/123?sig=HOSTSECRET',
            'https://example.com/workflows/123?sig=HOSTSECRET',
            'https://user@tenant.logic.azure.com/workflows/123?sig=USERSECRET',
            'https://tenant.logic.azure.com:444/workflows/123?sig=PORTSECRET',
            'https://tenant.logic.azure.com/workflows/123?sig=FRAGMENTSECRET#fragment',
            'https://tenant.logic.azure.com/?sig=PATHSECRET',
        ];

        $this->actingAs(User::factory()->create());

        foreach ($webhookUrls as $webhookUrl) {
            $this->from(route('monitors.create'))
                ->post(route('monitors.store'), $this->monitorPayload($webhookUrl))
                ->assertRedirect(route('monitors.create'))
                ->assertSessionHasErrors('notification_teams_webhook');

            $this->assertDatabaseCount('monitors', 0);
            $this->assertArrayNotHasKey('notification_teams_webhook', session()->get('_old_input', []));
        }
    }

    public function test_outbox_sends_a_bounded_text_payload_to_the_teams_workflow_and_marks_it_sent(): void
    {
        $webhookUrl = $this->teamsWebhookUrl();
        Http::fake([$webhookUrl => Http::response('', 202)]);
        $outbox = NotificationOutbox::factory()->create([
            'channel' => NotificationOutbox::CHANNEL_TEAMS,
            'recipient' => $webhookUrl,
            'subject' => '[Monitor] Campaign post changed',
            'body' => str_repeat('貼文狀態 ', 5000),
        ]);

        (new SendNotificationOutbox($outbox->id))->handle();

        $this->assertDatabaseHas('notification_outbox', [
            'id' => $outbox->id,
            'channel' => NotificationOutbox::CHANNEL_TEAMS,
            'status' => 'sent',
            'attempts' => 1,
        ]);
        Http::assertSent(fn (Request $request): bool => $request->url() === $webhookUrl
            && $request->isJson()
            && strlen((string) $request['text']) < 28_672
            && str_contains((string) $request['text'], 'Campaign post changed')
            && str_contains((string) $request['text'], '[內容已截斷]')
        );
    }

    public function test_teams_http_failure_and_invalid_outbox_destination_do_not_leak_the_webhook_secret(): void
    {
        $webhookUrl = $this->teamsWebhookUrl();
        Http::fake([$webhookUrl => Http::response('service error', 500)]);
        $outbox = NotificationOutbox::factory()->create([
            'channel' => NotificationOutbox::CHANNEL_TEAMS,
            'recipient' => $webhookUrl,
        ]);

        (new SendNotificationOutbox($outbox->id))->handle();

        $this->assertDatabaseHas('notification_outbox', [
            'id' => $outbox->id,
            'status' => 'unknown',
            'attempts' => 1,
        ]);
        $this->assertStringNotContainsString($webhookUrl, (string) $outbox->fresh()->last_error);
        $this->assertStringNotContainsString('MONITORTEAMSSECRET', (string) $outbox->fresh()->last_error);
        Http::assertSent(fn (Request $request): bool => $request->url() === $webhookUrl);

        Http::fake();
        $invalidOutbox = NotificationOutbox::factory()->create([
            'incident_id' => $outbox->incident_id,
            'channel' => NotificationOutbox::CHANNEL_TEAMS,
            'recipient' => 'https://attacker.test/collect?secret=PRIVATE',
        ]);

        (new SendNotificationOutbox($invalidOutbox->id))->handle();

        $this->assertDatabaseHas('notification_outbox', [
            'id' => $invalidOutbox->id,
            'status' => 'unknown',
        ]);
        $this->assertStringNotContainsString('PRIVATE', (string) $invalidOutbox->fresh()->last_error);
        Http::assertNothingSent();
    }

    /** @return array{name: string, url: string, notification_teams_webhook: string, expected_text_mode: string} */
    private function monitorPayload(string $webhookUrl): array
    {
        return [
            'name' => 'Fixture service',
            'url' => 'https://fixture.test/ok',
            'notification_teams_webhook' => $webhookUrl,
            'expected_text_mode' => 'present',
        ];
    }

    private function teamsWebhookUrl(): string
    {
        return 'https://tenant.environment.api.powerplatform.com/powerautomate/automations/direct/workflows/ABC123/triggers/manual/paths/invoke?sig=MONITORTEAMSSECRET';
    }
}
