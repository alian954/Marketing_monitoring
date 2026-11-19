<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationOutbox;
use App\Models\DnsChangeEvent;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
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
            ->expectsOutputToContain('已將 1 筆 DNS 變更加入摘要寄送佇列。');

        $outbox = NotificationOutbox::query()->sole();
        $this->assertNull($outbox->incident_id);
        $this->assertSame('dns_daily_summary', $outbox->event_type);
        $this->assertSame('owner@example.test', $outbox->recipient);
        $this->assertSame(64, strlen($outbox->event_key));
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

    public function test_empty_day_or_missing_recipient_does_not_create_an_outbox_entry(): void
    {
        Config::set('monitoring.notify_email', 'owner@example.test');
        $this->travelTo(Carbon::parse('2026-11-18 09:30:00', 'UTC'));

        $this->artisan('monitors:send-dns-summary')
            ->assertExitCode(0)
            ->expectsOutputToContain('沒有 DNS 變更');
        $this->assertDatabaseCount('notification_outbox', 0);

        Config::set('monitoring.notify_email', '');
        $this->artisan('monitors:send-dns-summary')
            ->assertExitCode(0)
            ->expectsOutputToContain('未設定通知收件人');
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
