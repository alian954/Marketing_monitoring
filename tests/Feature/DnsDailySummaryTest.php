<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationOutbox;
use App\Models\DnsChangeEvent;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class DnsDailySummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_command_sends_one_masked_summary_for_the_previous_day(): void
    {
        Config::set('monitoring.notify_email', 'owner@example.test');
        Config::set('monitoring.notify_slack_webhook', null);
        Config::set('monitoring.notify_discord_webhook', null);
        Queue::fake([SendNotificationOutbox::class]);
        $this->travelTo(Carbon::parse('2026-11-18 09:30:00', 'UTC'));
        $monitor = Monitor::factory()->create([
            'name' => 'Campaign landing',
            'url' => 'https://example.com/landing?email=owner%40example.test&token=dns-secret',
            'url_hash' => Monitor::fingerprintUrl('https://example.com/landing?email=owner%40example.test&token=dns-secret'),
        ]);
        DnsChangeEvent::factory()->for($monitor)->create([
            'previous_ips' => ['93.184.216.34'],
            'current_ips' => ['93.184.216.35'],
            'detected_at' => now()->subDay()->startOfDay()->addHours(12),
        ]);
        DnsChangeEvent::factory()->for($monitor)->create([
            'detected_at' => now()->startOfDay()->addHours(8),
        ]);

        $this->artisan('monitors:send-dns-summary')
            ->assertExitCode(0)
            ->expectsOutputToContain('已將 1 筆 DNS 變更摘要加入 1 個通知管道。');

        $outbox = NotificationOutbox::query()->sole();
        $this->assertNull($outbox->incident_id);
        $this->assertSame('dns_daily_summary', $outbox->event_type);
        $this->assertSame('owner@example.test', $outbox->recipient);
        $this->assertSame(hash('sha256', 'dns_daily_summary:unowned:owner@example.test:2026-11-17'), $outbox->event_key);
        $this->assertStringContainsString('2026-11-17', $outbox->subject);
        $this->assertStringContainsString('Campaign landing', $outbox->body);
        $this->assertStringContainsString('email=REDACTED&token=REDACTED', $outbox->body);
        $this->assertStringNotContainsString('dns-secret', $outbox->body);
        $this->assertStringContainsString('93.184.216.34', $outbox->body);
        $this->assertStringContainsString('93.184.216.35', $outbox->body);
        Queue::assertPushed(SendNotificationOutbox::class, 1);

        Mail::shouldReceive('raw')
            ->once()
            ->with($outbox->body, \Mockery::on(function (mixed $callback) use ($outbox): bool {
                if (! $callback instanceof \Closure) {
                    return false;
                }

                $mailMessage = new class
                {
                    public ?string $recipient = null;

                    public ?string $subject = null;

                    public function to(string $recipient): static
                    {
                        $this->recipient = $recipient;

                        return $this;
                    }

                    public function subject(string $subject): static
                    {
                        $this->subject = $subject;

                        return $this;
                    }
                };
                $callback($mailMessage);

                return $mailMessage->recipient === $outbox->recipient
                    && $mailMessage->subject === $outbox->subject;
            }));

        (new SendNotificationOutbox($outbox->id))->handle();
        $this->assertDatabaseHas('notification_outbox', [
            'id' => $outbox->id,
            'status' => 'sent',
            'attempts' => 1,
        ]);

        $this->artisan('monitors:send-dns-summary')->assertExitCode(0);

        $this->assertDatabaseCount('notification_outbox', 1);
        $this->assertDatabaseHas('notification_outbox', ['id' => $outbox->id, 'status' => 'sent']);
        Queue::assertPushed(SendNotificationOutbox::class, 1);
    }

    public function test_daily_dns_summaries_keep_each_accounts_monitor_details_separate(): void
    {
        Config::set('monitoring.notify_email', 'owner@example.test');
        Config::set('monitoring.notify_slack_webhook', null);
        Config::set('monitoring.notify_discord_webhook', null);
        Config::set('monitoring.notify_teams_webhook', null);
        Queue::fake([SendNotificationOutbox::class]);
        $this->travelTo(Carbon::parse('2026-11-18 09:30:00', 'UTC'));
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firstMonitor = Monitor::factory()->create([
            'name' => 'First account domain',
            'url' => 'https://first-account.example/landing',
            'url_hash' => Monitor::fingerprintUrl('https://first-account.example/landing'),
        ]);
        $firstMonitor->forceFill(['user_id' => $firstUser->id])->save();
        $secondMonitor = Monitor::factory()->create([
            'name' => 'Second account domain',
            'url' => 'https://second-account.example/landing',
            'url_hash' => Monitor::fingerprintUrl('https://second-account.example/landing'),
        ]);
        $secondMonitor->forceFill(['user_id' => $secondUser->id])->save();

        foreach ([$firstMonitor, $secondMonitor] as $monitor) {
            DnsChangeEvent::factory()->for($monitor)->create([
                'previous_ips' => ['93.184.216.34'],
                'current_ips' => ['93.184.216.35'],
                'detected_at' => now()->subDay()->startOfDay()->addHours(12),
            ]);
        }

        $this->artisan('monitors:send-dns-summary')
            ->assertExitCode(0)
            ->expectsOutputToContain('已將 2 筆 DNS 變更摘要加入 2 個通知管道。');

        $firstOutbox = NotificationOutbox::withoutGlobalScopes()->where('user_id', $firstUser->id)->sole();
        $secondOutbox = NotificationOutbox::withoutGlobalScopes()->where('user_id', $secondUser->id)->sole();

        $this->assertStringContainsString('First account domain', $firstOutbox->body);
        $this->assertStringNotContainsString('Second account domain', $firstOutbox->body);
        $this->assertStringContainsString('Second account domain', $secondOutbox->body);
        $this->assertStringNotContainsString('First account domain', $secondOutbox->body);
        Queue::assertPushed(SendNotificationOutbox::class, 2);
    }

    public function test_daily_summary_explains_a_dns_answer_without_public_ip_addresses(): void
    {
        Config::set('monitoring.notify_email', 'owner@example.test');
        Config::set('monitoring.notify_slack_webhook', null);
        Config::set('monitoring.notify_discord_webhook', null);
        Config::set('monitoring.notify_teams_webhook', null);
        Queue::fake([SendNotificationOutbox::class]);
        $this->travelTo(Carbon::parse('2026-11-18 09:30:00', 'UTC'));
        $monitor = Monitor::factory()->create();
        DnsChangeEvent::factory()->for($monitor)->create([
            'previous_ips' => ['93.184.216.34'],
            'current_ips' => [],
            'detected_at' => now()->subDay()->startOfDay()->addHours(12),
        ]);

        $this->artisan('monitors:send-dns-summary')->assertExitCode(0);

        $outbox = NotificationOutbox::query()->sole();
        $this->assertStringContainsString('變更後：無公開 IP 回答', $outbox->body);
    }

    public function test_daily_command_dispatches_a_masked_dns_summary_to_webhooks_without_email(): void
    {
        $slackWebhookUrl = 'https://hooks.slack.com/services/T123/SECRET/WEBHOOK';
        $discordWebhookUrl = 'https://discord.com/api/webhooks/456/SECRET';
        $teamsWebhookUrl = 'https://tenant.environment.api.powerplatform.com/powerautomate/workflows/dns-summary?sig=TEAMSSECRET';
        Config::set('monitoring.notify_email', null);
        Config::set('monitoring.notify_slack_webhook', $slackWebhookUrl);
        Config::set('monitoring.notify_discord_webhook', $discordWebhookUrl);
        Config::set('monitoring.notify_teams_webhook', $teamsWebhookUrl);
        Queue::fake([SendNotificationOutbox::class]);
        $this->travelTo(Carbon::parse('2026-11-18 09:30:00', 'UTC'));
        $monitor = Monitor::factory()->create([
            'name' => 'Campaign landing',
            'url' => 'https://example.com/landing?token=dns-secret',
            'url_hash' => Monitor::fingerprintUrl('https://example.com/landing?token=dns-secret'),
        ]);
        DnsChangeEvent::factory()->for($monitor)->create([
            'previous_ips' => ['93.184.216.34'],
            'current_ips' => ['93.184.216.35'],
            'detected_at' => now()->subDay()->startOfDay()->addHours(12),
        ]);

        $this->artisan('monitors:send-dns-summary')
            ->assertExitCode(0)
            ->expectsOutputToContain('已將 1 筆 DNS 變更摘要加入 3 個通知管道。');

        $outboxes = NotificationOutbox::query()->orderBy('channel')->get();
        $this->assertSame([NotificationOutbox::CHANNEL_DISCORD, NotificationOutbox::CHANNEL_SLACK, NotificationOutbox::CHANNEL_TEAMS], $outboxes->pluck('channel')->all());
        $this->assertSame(3, $outboxes->pluck('event_key')->unique()->count());
        $this->assertTrue($outboxes->every(static fn (NotificationOutbox $outbox): bool => $outbox->event_type === 'dns_daily_summary'
            && $outbox->status === 'pending'
            && str_contains($outbox->body, 'token=REDACTED')
            && ! str_contains($outbox->body, 'dns-secret')));
        $encryptedRecipients = DB::table('notification_outbox')->pluck('recipient')->all();
        $this->assertNotContains($slackWebhookUrl, $encryptedRecipients);
        $this->assertNotContains($discordWebhookUrl, $encryptedRecipients);
        $this->assertNotContains($teamsWebhookUrl, $encryptedRecipients);
        Queue::assertPushed(SendNotificationOutbox::class, 3);

        Http::fake([
            $slackWebhookUrl => Http::response('', 200),
            $discordWebhookUrl.'?wait=true' => Http::response('ok', 204),
            'https://tenant.environment.api.powerplatform.com/*' => Http::response('', 202),
        ]);
        Mail::fake();

        $outboxes->each(static fn (NotificationOutbox $outbox) => (new SendNotificationOutbox($outbox->id))->handle());

        $this->assertSame(3, NotificationOutbox::query()->where('status', 'sent')->count());
        Http::assertSent(fn (Request $request): bool => $request->url() === $slackWebhookUrl
            && str_contains($request['text'], '93.184.216.34')
            && str_contains($request['text'], '93.184.216.35'));
        Http::assertSent(fn (Request $request): bool => $request->url() === $discordWebhookUrl.'?wait=true'
            && str_contains($request['content'], 'DNS 變更每日摘要')
            && $request['allowed_mentions'] === ['parse' => []]);
        Http::assertSent(fn (Request $request): bool => $request->url() === $teamsWebhookUrl
            && str_contains((string) $request['text'], 'DNS 變更每日摘要'));

        $this->artisan('monitors:send-dns-summary')->assertExitCode(0);
        $this->assertDatabaseCount('notification_outbox', 3);
        Queue::assertPushed(SendNotificationOutbox::class, 3);
    }

    public function test_empty_day_or_missing_recipient_does_not_create_an_outbox_entry(): void
    {
        Config::set('monitoring.notify_email', 'owner@example.test');
        Config::set('monitoring.notify_slack_webhook', null);
        Config::set('monitoring.notify_discord_webhook', null);
        Config::set('monitoring.notify_teams_webhook', null);
        $this->travelTo(Carbon::parse('2026-11-18 09:30:00', 'UTC'));

        $this->artisan('monitors:send-dns-summary')
            ->assertExitCode(0)
            ->expectsOutputToContain('沒有 DNS 變更');
        $this->assertDatabaseCount('notification_outbox', 0);

        Config::set('monitoring.notify_email', '');
        $this->artisan('monitors:send-dns-summary')
            ->assertExitCode(0)
            ->expectsOutputToContain('未設定任何通知管道');
        $this->assertDatabaseCount('notification_outbox', 0);
    }

    public function test_daily_dns_summary_command_is_scheduled_once_per_day(): void
    {
        $event = collect(Schedule::events())->first(
            static fn ($event): bool => str_contains((string) $event->command, 'monitors:send-dns-summary'),
        );

        $this->assertNotNull($event);
        $this->assertSame('0 6 * * *', $event->expression);
    }
}
