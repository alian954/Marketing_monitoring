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
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonitorNotificationRecipientTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_notification_email_is_encrypted_and_overrides_the_global_recipient(): void
    {
        Config::set('monitoring.notify_email', 'global@example.test');
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = Monitor::factory()->create(['notification_email' => 'alerts@example.test']);
        $run = CheckRun::factory()->for($monitor)->create();
        $incident = Incident::factory()->for($monitor)->create();

        app(NotificationDispatcher::class)->queue($incident, $monitor, $run, 'opened');

        $outbox = NotificationOutbox::query()->sole();
        $encryptedEmail = DB::table('monitors')->where('id', $monitor->id)->value('notification_email');
        $encryptedOutboxRecipient = DB::table('notification_outbox')->where('id', $outbox->id)->value('recipient');

        $this->assertSame('alerts@example.test', $outbox->recipient);
        $this->assertSame('alerts@example.test', $monitor->fresh()->notification_email);
        $this->assertNotSame('alerts@example.test', $encryptedEmail);
        $this->assertNotSame('alerts@example.test', $encryptedOutboxRecipient);
        $this->assertArrayNotHasKey('recipient', $outbox->toArray());
        Queue::assertPushed(SendNotificationOutbox::class);
    }

    public function test_monitor_notification_email_supports_multiple_deduplicated_recipients(): void
    {
        Config::set('monitoring.transport', 'fixture');
        Config::set('monitoring.notify_email', 'global@example.test');
        Queue::fake([SendNotificationOutbox::class]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('monitors.store'), [
                'name' => 'Fixture post',
                'url' => 'https://fixture.test/post/1',
                'notification_email' => " alerts@example.test, team@example.test;\nALERTS@example.test ",
                'expected_text_mode' => 'present',
            ])
            ->assertSessionHasNoErrors();

        $monitor = Monitor::query()->sole();
        $this->assertSame("alerts@example.test\nteam@example.test", $monitor->notification_email);
        $this->assertNotSame($monitor->notification_email, DB::table('monitors')->where('id', $monitor->id)->value('notification_email'));
        $this->get(route('monitors.edit', $monitor))
            ->assertOk()
            ->assertSee('alerts@example.test'."\n".'team@example.test', false)
            ->assertSee('最多 10 個信箱');

        $run = CheckRun::factory()->for($monitor)->create();
        $incident = Incident::factory()->for($monitor)->create();
        $dispatcher = app(NotificationDispatcher::class);
        $dispatcher->queue($incident, $monitor, $run, 'opened');
        $dispatcher->queue($incident, $monitor, $run, 'opened');

        $outboxes = NotificationOutbox::query()->where('channel', 'email')->get();

        $this->assertCount(2, $outboxes);
        $this->assertEqualsCanonicalizing(['alerts@example.test', 'team@example.test'], $outboxes->pluck('recipient')->all());
        $this->assertDatabaseMissing('notification_outbox', ['recipient' => 'global@example.test']);
        Queue::assertPushed(SendNotificationOutbox::class, 2);

        Mail::fake();
        $outboxes->each(static fn (NotificationOutbox $outbox) => (new SendNotificationOutbox($outbox->id))->handle());

        $this->assertSame(2, NotificationOutbox::query()->where('status', 'sent')->count());
        $this->assertSame(2, NotificationOutbox::query()->where('attempts', 1)->count());
    }

    public function test_global_notification_email_supports_multiple_recipients(): void
    {
        Config::set('monitoring.notify_email', 'global@example.test, backup@example.test; GLOBAL@example.test');
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = Monitor::factory()->create(['notification_email' => null]);
        $run = CheckRun::factory()->for($monitor)->create();
        $incident = Incident::factory()->for($monitor)->create();

        app(NotificationDispatcher::class)->queue($incident, $monitor, $run, 'opened');

        $this->assertEqualsCanonicalizing(
            ['global@example.test', 'backup@example.test'],
            NotificationOutbox::query()->where('channel', 'email')->get()->pluck('recipient')->all(),
        );
        Queue::assertPushed(SendNotificationOutbox::class, 2);
    }

    public function test_blank_monitor_notification_email_uses_the_global_recipient(): void
    {
        Config::set('monitoring.notify_email', 'global@example.test');
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = Monitor::factory()->create(['notification_email' => null]);
        $run = CheckRun::factory()->for($monitor)->create();
        $incident = Incident::factory()->for($monitor)->create();

        app(NotificationDispatcher::class)->queue($incident, $monitor, $run, 'opened');

        $this->assertSame('global@example.test', NotificationOutbox::query()->sole()->recipient);
        Queue::assertPushed(SendNotificationOutbox::class);
    }

    public function test_outbox_migration_encrypts_legacy_recipients_idempotently(): void
    {
        $outbox = NotificationOutbox::factory()->create(['incident_id' => null]);
        DB::table('notification_outbox')->where('id', $outbox->id)->update([
            'recipient' => 'legacy@example.test',
        ]);
        $migration = require database_path('migrations/2026_11_18_160637_encrypt_notification_outbox_recipients.php');

        $migration->up();

        $encryptedRecipient = DB::table('notification_outbox')->where('id', $outbox->id)->value('recipient');
        $this->assertNotSame('legacy@example.test', $encryptedRecipient);
        $this->assertSame('legacy@example.test', $outbox->fresh()->recipient);

        $migration->up();

        $this->assertSame($encryptedRecipient, DB::table('notification_outbox')->where('id', $outbox->id)->value('recipient'));
        $this->assertSame('legacy@example.test', $outbox->fresh()->recipient);
    }

    public function test_monitor_notification_email_can_be_set_and_cleared_from_the_form(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('monitors.store'), [
                'name' => 'Fixture post',
                'url' => 'https://fixture.test/post/1',
                'notification_email' => ' alerts@example.test ',
                'expected_text_mode' => 'present',
            ])
            ->assertSessionHasNoErrors();

        $monitor = Monitor::query()->sole();
        $encryptedEmail = DB::table('monitors')->where('id', $monitor->id)->value('notification_email');

        $this->assertSame('alerts@example.test', $monitor->notification_email);
        $this->assertNotSame('alerts@example.test', $encryptedEmail);
        $this->get(route('monitors.edit', $monitor))
            ->assertOk()
            ->assertSee('>alerts@example.test</textarea>', false);

        $this->put(route('monitors.update', $monitor), [
            'name' => $monitor->name,
            'url' => '',
            'notification_email' => '   ',
            'expected_text_mode' => 'present',
        ])->assertSessionHasNoErrors();

        $this->assertNull($monitor->fresh()->notification_email);
    }

    public function test_monitor_notification_email_rejects_invalid_addresses(): void
    {
        Config::set('monitoring.transport', 'fixture');

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->post(route('monitors.store'), [
                'name' => 'Fixture post',
                'url' => 'https://fixture.test/post/1',
                'notification_email' => 'not-an-email',
                'expected_text_mode' => 'present',
            ])
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors('notification_email');

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_monitor_notification_email_rejects_mixed_valid_and_invalid_addresses(): void
    {
        Config::set('monitoring.transport', 'fixture');

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->post(route('monitors.store'), [
                'name' => 'Fixture post',
                'url' => 'https://fixture.test/post/1',
                'notification_email' => "valid@example.test\ninvalid-address",
                'expected_text_mode' => 'present',
            ])
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors('notification_email');

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_monitor_notification_email_rejects_more_than_ten_recipients(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $recipients = collect(range(1, 11))->map(static fn (int $index): string => "ops{$index}@example.test")->implode(', ');

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->post(route('monitors.store'), [
                'name' => 'Fixture post',
                'url' => 'https://fixture.test/post/1',
                'notification_email' => $recipients,
                'expected_text_mode' => 'present',
            ])
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors('notification_email');

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_monitor_notification_email_rejects_non_string_values(): void
    {
        Config::set('monitoring.transport', 'fixture');

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->post(route('monitors.store'), [
                'name' => 'Fixture post',
                'url' => 'https://fixture.test/post/1',
                'notification_email' => ['invalid@example.test'],
                'expected_text_mode' => 'present',
            ])
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors('notification_email');

        $this->assertDatabaseCount('monitors', 0);
    }
}
