<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationOutbox;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationTestDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_send_a_test_notification(): void
    {
        $this->post(route('notifications.test'))->assertRedirect(route('login'));
        $this->post(route('monitors.notifications.test', Monitor::factory()->create()))
            ->assertRedirect(route('login'));
    }

    public function test_test_notifications_share_a_per_user_rate_limit_across_global_and_monitor_routes(): void
    {
        Config::set('monitoring.notify_email', 'ops@example.test');
        Config::set('monitoring.notify_slack_webhook', null);
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = Monitor::factory()->create();

        $this->actingAs(User::factory()->create());

        $routes = [
            route('notifications.test'),
            route('monitors.notifications.test', $monitor),
            route('notifications.test'),
            route('monitors.notifications.test', $monitor),
            route('notifications.test'),
        ];

        foreach ($routes as $route) {
            $this->post($route)->assertRedirect();
        }

        $this->post(route('monitors.notifications.test', $monitor))->assertTooManyRequests();

        $this->assertDatabaseCount('notification_outbox', 5);
        Queue::assertPushed(SendNotificationOutbox::class, 5);

        $this->actingAs(User::factory()->create())
            ->post(route('notifications.test'))
            ->assertRedirect();

        $this->assertDatabaseCount('notification_outbox', 6);
        Queue::assertPushed(SendNotificationOutbox::class, 6);
    }

    public function test_test_notifications_are_queued_for_each_configured_destination_and_can_be_filtered(): void
    {
        $webhookUrl = 'https://hooks.slack.com/services/T123/SECRET/WEBHOOK';
        Config::set('monitoring.notify_email', 'ops@example.test, backup@example.test; OPS@example.test');
        Config::set('monitoring.notify_slack_webhook', $webhookUrl);
        Queue::fake([SendNotificationOutbox::class]);

        $this->actingAs(User::factory()->create())
            ->post(route('notifications.test'))
            ->assertRedirect(route('notifications.index', ['event_type' => NotificationOutbox::EVENT_TYPE_TEST]))
            ->assertSessionHas('status');

        $outboxes = NotificationOutbox::query()->orderBy('id')->get();
        $this->assertCount(3, $outboxes);
        $this->assertSame(3, $outboxes->pluck('event_key')->unique()->count());
        $this->assertSame(3, $outboxes->where('event_type', NotificationOutbox::EVENT_TYPE_TEST)->count());
        $this->assertSame(3, $outboxes->whereNull('incident_id')->count());
        $this->assertSame(0, $outboxes->whereNotNull('monitor_id')->count());
        $this->assertSame(['backup@example.test', 'ops@example.test'], $outboxes->where('channel', NotificationOutbox::CHANNEL_EMAIL)->pluck('recipient')->sort()->values()->all());
        $this->assertSame($webhookUrl, $outboxes->where('channel', NotificationOutbox::CHANNEL_SLACK)->sole()->recipient);
        $this->assertTrue($outboxes->every(static fn (NotificationOutbox $outbox): bool => $outbox->status === 'pending'
            && str_contains($outbox->subject, '測試通知')
            && str_contains($outbox->body, '這是一則測試通知')));
        $encryptedRecipients = DB::table('notification_outbox')->pluck('recipient')->all();
        $this->assertNotContains('ops@example.test', $encryptedRecipients);
        $this->assertNotContains($webhookUrl, $encryptedRecipients);
        Queue::assertPushed(SendNotificationOutbox::class, 3);

        $this->get(route('notifications.index', ['event_type' => NotificationOutbox::EVENT_TYPE_TEST]))
            ->assertOk()
            ->assertSee('已排入 3 筆測試通知')
            ->assertSee('3 筆符合條件的通知')
            ->assertSee('測試通知')
            ->assertSee('o***s@example.test')
            ->assertSee('b***p@example.test')
            ->assertSee('Webhook 端點已遮蔽')
            ->assertDontSee($webhookUrl);

        $csv = $this->get(route('notifications.export', ['event_type' => NotificationOutbox::EVENT_TYPE_TEST]))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('測試通知', $csv);
        $this->assertStringNotContainsString('ops@example.test', $csv);
        $this->assertStringNotContainsString($webhookUrl, $csv);

        Mail::fake();
        Http::fake([$webhookUrl => Http::response('', 200)]);
        $outboxes->each(static fn (NotificationOutbox $outbox) => (new SendNotificationOutbox($outbox->id))->handle());

        $this->assertSame(3, NotificationOutbox::query()->where('status', 'sent')->count());
        Http::assertSent(fn (Request $request): bool => $request->url() === $webhookUrl
            && str_contains($request['text'], '測試通知'));
    }

    public function test_monitor_test_uses_its_email_list_and_global_slack_and_filters_history(): void
    {
        $webhookUrl = 'https://hooks.slack.com/services/T123/SECRET/WEBHOOK';
        Config::set('monitoring.notify_email', 'global@example.test');
        Config::set('monitoring.notify_slack_webhook', $webhookUrl);
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = Monitor::factory()->create([
            'name' => 'Campaign post',
            'notification_email' => "owner@example.test\nbackup@example.test",
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('測試通知')
            ->assertSee(route('monitors.notifications.test', $monitor));

        $this->post(route('monitors.notifications.test', $monitor))
            ->assertRedirect(route('notifications.index', [
                'event_type' => NotificationOutbox::EVENT_TYPE_TEST,
                'monitor_id' => $monitor->id,
            ]))
            ->assertSessionHas('status');

        $outboxes = NotificationOutbox::query()->orderBy('id')->get();
        $this->assertCount(3, $outboxes);
        $this->assertSame(3, $outboxes->where('monitor_id', $monitor->id)->count());
        $this->assertEqualsCanonicalizing(
            ['backup@example.test', 'owner@example.test'],
            $outboxes->where('channel', NotificationOutbox::CHANNEL_EMAIL)->pluck('recipient')->all(),
        );
        $this->assertSame($webhookUrl, $outboxes->where('channel', NotificationOutbox::CHANNEL_SLACK)->sole()->recipient);
        $this->assertTrue($outboxes->every(static fn (NotificationOutbox $outbox): bool => str_contains($outbox->subject, 'Campaign post')));
        Queue::assertPushed(SendNotificationOutbox::class, 3);

        $this->get(route('notifications.index', [
            'event_type' => NotificationOutbox::EVENT_TYPE_TEST,
            'monitor_id' => $monitor->id,
        ]))
            ->assertOk()
            ->assertSee('3 筆符合條件的通知')
            ->assertSee('Campaign post')
            ->assertSee('o***r@example.test')
            ->assertSee('b***p@example.test');

        $csv = $this->get(route('notifications.export', [
            'event_type' => NotificationOutbox::EVENT_TYPE_TEST,
            'monitor_id' => $monitor->id,
        ]))->assertOk()->streamedContent();
        $this->assertSame(3, substr_count($csv, 'Campaign post'));
        $this->assertStringNotContainsString('owner@example.test', $csv);
        $this->assertStringNotContainsString($webhookUrl, $csv);

        $monitor->delete();

        $this->assertSame(3, NotificationOutbox::query()->count());
        $this->assertSame(0, NotificationOutbox::query()->whereNotNull('monitor_id')->count());
    }

    public function test_monitor_test_falls_back_to_global_email_when_its_custom_list_is_blank(): void
    {
        Config::set('monitoring.notify_email', 'global@example.test');
        Config::set('monitoring.notify_slack_webhook', null);
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = Monitor::factory()->create(['notification_email' => null]);

        $this->actingAs(User::factory()->create())
            ->post(route('monitors.notifications.test', $monitor))
            ->assertRedirect(route('notifications.index', [
                'event_type' => NotificationOutbox::EVENT_TYPE_TEST,
                'monitor_id' => $monitor->id,
            ]));

        $outbox = NotificationOutbox::query()->sole();
        $this->assertSame($monitor->id, $outbox->monitor_id);
        $this->assertSame('global@example.test', $outbox->recipient);
        Queue::assertPushed(SendNotificationOutbox::class, 1);
    }

    public function test_monitor_test_shows_an_error_when_no_monitor_or_global_destination_is_configured(): void
    {
        Config::set('monitoring.notify_email', null);
        Config::set('monitoring.notify_slack_webhook', null);
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = Monitor::factory()->create(['notification_email' => null]);

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.show', $monitor))
            ->followingRedirects()
            ->post(route('monitors.notifications.test', $monitor))
            ->assertOk()
            ->assertSee('尚未設定此監測的收件信箱或全域通知管道。');

        $this->assertDatabaseCount('notification_outbox', 0);
        Queue::assertNothingPushed();
    }

    public function test_test_notification_requires_at_least_one_configured_destination(): void
    {
        Config::set('monitoring.notify_email', null);
        Config::set('monitoring.notify_slack_webhook', ' ');
        Queue::fake([SendNotificationOutbox::class]);

        $this->actingAs(User::factory()->create())
            ->from(route('notifications.index'))
            ->followingRedirects()
            ->post(route('notifications.test'))
            ->assertOk()
            ->assertSee('尚未設定全域通知收件信箱或 Slack webhook。');

        $this->assertDatabaseCount('notification_outbox', 0);
        Queue::assertNothingPushed();
    }
}
