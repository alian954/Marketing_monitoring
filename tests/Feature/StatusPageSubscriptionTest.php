<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationOutbox;
use App\Jobs\SendStatusPageSubscriptionConfirmation;
use App\Mail\StatusPageSubscriptionConfirmation;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\StatusPage;
use App\Models\StatusPageSubscription;
use App\Services\IncidentManager;
use App\Services\NotificationDispatcher;
use App\Services\Probe\ProbeResult;
use App\Services\StatusPageSubscriptionDispatcher;
use App\Services\UrlMasker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class StatusPageSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_status_page_has_an_accessible_subscription_form(): void
    {
        $statusPage = $this->publishedStatusPage();
        Monitor::factory()->create(['public_status' => true]);

        $this->get(route('status-pages.public', ['token' => $statusPage->public_token]))
            ->assertOk()
            ->assertSee('訂閱服務更新')
            ->assertSee('name="email"', false)
            ->assertSee('type="email"', false)
            ->assertSee('autocomplete="email"', false)
            ->assertSee('inputmode="email"', false)
            ->assertSee('name="website"', false)
            ->assertSee('method="POST"', false);
    }

    public function test_subscription_stores_encrypted_email_and_token_and_queues_confirmation(): void
    {
        Queue::fake([SendStatusPageSubscriptionConfirmation::class]);
        $statusPage = $this->publishedStatusPage();

        $this->post(route('status-page-subscriptions.subscribe', ['token' => $statusPage->public_token]), [
            'email' => 'Owner@example.test',
        ])
            ->assertRedirect(route('status-pages.public', ['token' => $statusPage->public_token]))
            ->assertSessionHas('status');

        $subscription = StatusPageSubscription::query()->sole();
        $storedEmail = DB::table('status_page_subscriptions')->where('id', $subscription->id)->value('email');
        $storedToken = DB::table('status_page_subscriptions')->where('id', $subscription->id)->value('confirmation_token');

        $this->assertSame('Owner@example.test', $subscription->email);
        $this->assertSame($statusPage->id, $subscription->status_page_id);
        $this->assertNull($subscription->confirmed_at);
        $this->assertSame(hash('sha256', $subscription->confirmation_token), $subscription->confirmation_token_hash);
        $this->assertNotSame('Owner@example.test', $storedEmail);
        $this->assertNotSame($subscription->confirmation_token, $storedToken);
        $this->assertSame(64, strlen($subscription->confirmation_token_hash));

        Queue::assertPushed(
            SendStatusPageSubscriptionConfirmation::class,
            fn (SendStatusPageSubscriptionConfirmation $job): bool => $job->subscriptionId === $subscription->id,
        );
    }

    public function test_honeypot_and_invalid_email_do_not_create_or_send_subscriptions(): void
    {
        Queue::fake([SendStatusPageSubscriptionConfirmation::class]);
        $statusPage = $this->publishedStatusPage();
        $subscribeUrl = route('status-page-subscriptions.subscribe', ['token' => $statusPage->public_token]);

        $this->from(route('status-pages.public', ['token' => $statusPage->public_token]))
            ->post($subscribeUrl, [
                'email' => 'bot@example.test',
                'website' => 'https://spam.example',
            ])
            ->assertRedirect(route('status-pages.public', ['token' => $statusPage->public_token]))
            ->assertSessionHas('status');
        $this->from(route('status-pages.public', ['token' => $statusPage->public_token]))
            ->post($subscribeUrl, ['email' => 'invalid'])
            ->assertRedirect(route('status-pages.public', ['token' => $statusPage->public_token]))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('status_page_subscriptions', 0);
        Queue::assertNothingPushed();
    }

    public function test_duplicate_pending_requests_rotate_the_confirmation_link_without_duplicating_the_subscription(): void
    {
        Queue::fake([SendStatusPageSubscriptionConfirmation::class]);
        $statusPage = $this->publishedStatusPage();
        $url = route('status-page-subscriptions.subscribe', ['token' => $statusPage->public_token]);

        $this->post($url, ['email' => 'owner@example.test'])->assertRedirect();
        $subscription = StatusPageSubscription::query()->sole();
        $firstTokenHash = $subscription->confirmation_token_hash;
        $firstToken = $subscription->confirmation_token;

        $this->post($url, ['email' => 'OWNER@example.test'])->assertRedirect();

        $subscription->refresh();
        $this->assertDatabaseCount('status_page_subscriptions', 1);
        $this->assertSame('OWNER@example.test', $subscription->email);
        $this->assertNotSame($firstTokenHash, $subscription->confirmation_token_hash);
        $this->assertNotSame($firstToken, $subscription->confirmation_token);
        Queue::assertPushed(SendStatusPageSubscriptionConfirmation::class, 2);
    }

    public function test_existing_confirmed_subscription_is_not_duplicated_or_sent_another_confirmation(): void
    {
        Queue::fake([SendStatusPageSubscriptionConfirmation::class]);
        $statusPage = $this->publishedStatusPage();
        $subscription = StatusPageSubscription::factory()->for($statusPage)->create([
            'email' => 'owner@example.test',
            'email_hash' => hash_hmac('sha256', 'owner@example.test', (string) config('app.key')),
            'confirmed_at' => now(),
        ]);
        $originalTokenHash = $subscription->confirmation_token_hash;

        $this->post(route('status-page-subscriptions.subscribe', ['token' => $statusPage->public_token]), [
            'email' => 'OWNER@example.test',
        ])
            ->assertRedirect(route('status-pages.public', ['token' => $statusPage->public_token]))
            ->assertSessionHas('status');

        $subscription->refresh();
        $this->assertDatabaseCount('status_page_subscriptions', 1);
        $this->assertTrue($subscription->isActive());
        $this->assertSame($originalTokenHash, $subscription->confirmation_token_hash);
        Queue::assertNothingPushed();
    }

    public function test_confirmation_email_uses_the_encrypted_token_and_subscription_requires_a_post_confirmation(): void
    {
        Queue::fake([SendStatusPageSubscriptionConfirmation::class]);
        Mail::fake();
        $statusPage = $this->publishedStatusPage();
        $this->post(route('status-page-subscriptions.subscribe', ['token' => $statusPage->public_token]), [
            'email' => 'owner@example.test',
        ])->assertRedirect();
        $subscription = StatusPageSubscription::query()->sole();
        $confirmationToken = $subscription->confirmation_token;
        $confirmationUrl = route('status-page-subscriptions.confirm', [
            'subscription' => $subscription->id,
            'token' => $confirmationToken,
        ]);

        (new SendStatusPageSubscriptionConfirmation($subscription->id))->handle();

        Mail::assertSent(StatusPageSubscriptionConfirmation::class, function (StatusPageSubscriptionConfirmation $mail) use ($confirmationToken, $statusPage): bool {
            return $mail->statusPageTitle === $statusPage->title
                && str_contains($mail->confirmationUrl, $confirmationToken);
        });

        $this->get($confirmationUrl)
            ->assertOk()
            ->assertSee('確認狀態更新訂閱')
            ->assertSee('確認訂閱');
        $this->assertNull($subscription->fresh()->confirmed_at);

        $this->post($confirmationUrl)
            ->assertOk()
            ->assertSee('訂閱已確認');

        $subscription->refresh();
        $this->assertTrue($subscription->isActive());
        $this->assertNull($subscription->confirmation_token);
        $this->assertNull($subscription->confirmation_expires_at);
        $this->get($confirmationUrl)->assertOk()->assertSee('訂閱已確認');
    }

    public function test_expired_confirmation_link_cannot_activate_a_subscription(): void
    {
        $statusPage = $this->publishedStatusPage();
        $token = Str::random(64);
        $subscription = StatusPageSubscription::factory()->for($statusPage)->create([
            'confirmed_at' => null,
            'confirmation_token' => $token,
            'confirmation_token_hash' => hash('sha256', $token),
            'confirmation_expires_at' => now()->subSecond(),
        ]);
        $confirmationUrl = route('status-page-subscriptions.confirm', [
            'subscription' => $subscription->id,
            'token' => $token,
        ]);

        $this->get($confirmationUrl)->assertOk()->assertSee('確認連結已失效');
        $this->post($confirmationUrl)->assertNotFound();

        $this->assertNull($subscription->fresh()->confirmed_at);
    }

    public function test_unsubscribe_requires_a_signed_post_and_cancels_pending_notifications(): void
    {
        Queue::fake([SendNotificationOutbox::class]);
        Mail::fake();
        $statusPage = $this->publishedStatusPage();
        $monitor = Monitor::factory()->create(['public_status' => true]);
        $subscription = StatusPageSubscription::factory()->for($statusPage)->create();
        $incident = Incident::factory()->for($monitor)->create();
        $outbox = NotificationOutbox::factory()->create([
            'incident_id' => $incident->id,
            'monitor_id' => $monitor->id,
            'status_page_subscription_id' => $subscription->id,
            'event_type' => NotificationOutbox::EVENT_TYPE_STATUS_OPENED,
            'recipient' => $subscription->email,
            'status' => 'pending',
        ]);
        $unsubscribeUrl = URL::signedRoute('status-page-subscriptions.unsubscribe', [
            'subscription' => $subscription->id,
        ]);

        $this->get($unsubscribeUrl)
            ->assertOk()
            ->assertSee('取消狀態更新訂閱');
        $this->assertNull($subscription->fresh()->unsubscribed_at);
        $this->get($unsubscribeUrl.'&tampered=1')->assertForbidden();

        $this->followingRedirects()
            ->post($unsubscribeUrl)
            ->assertOk()
            ->assertSee('訂閱已取消');

        $subscription->refresh();
        $this->assertNotNull($subscription->unsubscribed_at);
        $this->assertNull($subscription->email);
        $this->assertNull($subscription->email_hash);
        $this->assertDatabaseHas('notification_outbox', ['id' => $outbox->id, 'status' => 'cancelled']);

        (new SendNotificationOutbox($outbox->id))->handle();

        Mail::assertOutgoingCount(0);
        Queue::assertNothingPushed();
    }

    public function test_public_subscribers_receive_only_public_incident_open_and_recovery_updates(): void
    {
        Queue::fake([SendNotificationOutbox::class]);
        config()->set('monitoring.notify_email', null);
        config()->set('monitoring.notify_slack_webhook', null);
        $statusPage = $this->publishedStatusPage();
        $monitorUrl = 'https://private.example/campaign?token=private';
        $monitor = Monitor::factory()->create([
            'url' => $monitorUrl,
            'url_hash' => Monitor::fingerprintUrl($monitorUrl),
            'name' => 'Campaign landing page',
            'public_status' => true,
            'expected_text' => 'private campaign phrase',
        ]);
        $activeSubscription = StatusPageSubscription::factory()->for($statusPage)->create([
            'email' => 'subscriber@example.test',
            'confirmed_at' => now(),
        ]);
        $pendingSubscription = StatusPageSubscription::factory()->for($statusPage)->create([
            'confirmed_at' => null,
        ]);
        $manager = new IncidentManager(new NotificationDispatcher(
            new UrlMasker,
            app(StatusPageSubscriptionDispatcher::class),
        ));
        $failure = new ProbeResult(503, 120, 'https://private.example/campaign?token=REDACTED', [], ['93.184.216.34'], null, null, null, null);
        $success = new ProbeResult(200, 80, 'https://private.example/campaign', [], ['93.184.216.34'], null, null, null, null);
        $firstFailure = CheckRun::factory()->for($monitor)->create(['normal_status' => 503]);
        $manager->record($monitor, $firstFailure, $failure, null);

        $this->travelTo(now()->addSeconds((int) config('monitoring.confirmation_delay_seconds') + 1));
        $secondFailure = CheckRun::factory()->for($monitor)->create(['normal_status' => 503]);
        $manager->record($monitor, $secondFailure, $failure, null);

        $openedOutbox = NotificationOutbox::query()
            ->where('status_page_subscription_id', $activeSubscription->id)
            ->sole();
        $this->assertSame(NotificationOutbox::EVENT_TYPE_STATUS_OPENED, $openedOutbox->event_type);
        $this->assertSame('subscriber@example.test', $openedOutbox->recipient);
        $this->assertStringContainsString('Campaign landing page', $openedOutbox->body);
        $this->assertStringContainsString('取消訂閱：', $openedOutbox->body);
        $this->assertStringNotContainsString('private.example', $openedOutbox->body);
        $this->assertStringNotContainsString('private campaign phrase', $openedOutbox->body);
        $this->assertDatabaseMissing('notification_outbox', ['status_page_subscription_id' => $pendingSubscription->id]);

        $this->travelTo(now()->addSeconds((int) config('monitoring.confirmation_delay_seconds') + 1));
        $firstRecovery = CheckRun::factory()->for($monitor)->create(['normal_status' => 200]);
        $manager->record($monitor, $firstRecovery, $success, null);
        $this->travelTo(now()->addSeconds((int) config('monitoring.confirmation_delay_seconds') + 1));
        $secondRecovery = CheckRun::factory()->for($monitor)->create(['normal_status' => 200]);
        $manager->record($monitor, $secondRecovery, $success, null);

        $this->assertDatabaseHas('notification_outbox', [
            'status_page_subscription_id' => $activeSubscription->id,
            'event_type' => NotificationOutbox::EVENT_TYPE_STATUS_RECOVERED,
            'status' => 'pending',
        ]);
        $this->assertSame(2, NotificationOutbox::query()->where('status_page_subscription_id', $activeSubscription->id)->count());
        $this->assertNotContains('subscriber@example.test', DB::table('notification_outbox')->pluck('recipient')->all());
        Queue::assertPushed(SendNotificationOutbox::class, 2);
    }

    public function test_public_subscribe_form_is_rate_limited_by_ip_and_email(): void
    {
        Queue::fake([SendStatusPageSubscriptionConfirmation::class]);
        $statusPage = $this->publishedStatusPage();
        $url = route('status-page-subscriptions.subscribe', ['token' => $statusPage->public_token]);

        for ($index = 1; $index <= 5; $index++) {
            $this->post($url, ['email' => 'visitor'.$index.'@example.test'])->assertRedirect();
        }

        $this->post($url, ['email' => 'visitor6@example.test'])->assertTooManyRequests();
        $this->assertDatabaseCount('status_page_subscriptions', 5);
    }

    public function test_public_subscribe_form_is_rate_limited_by_email_across_different_ips(): void
    {
        Queue::fake([SendStatusPageSubscriptionConfirmation::class]);
        $statusPage = $this->publishedStatusPage();
        $url = route('status-page-subscriptions.subscribe', ['token' => $statusPage->public_token]);

        foreach (['192.0.2.10', '192.0.2.11', '192.0.2.12'] as $ipAddress) {
            $this->withServerVariables(['REMOTE_ADDR' => $ipAddress])
                ->post($url, ['email' => 'visitor@example.test'])
                ->assertRedirect();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.13'])
            ->post($url, ['email' => 'VISITOR@example.test'])
            ->assertTooManyRequests();

        $this->assertDatabaseCount('status_page_subscriptions', 1);
        Queue::assertPushed(SendStatusPageSubscriptionConfirmation::class, 3);
    }

    private function publishedStatusPage(): StatusPage
    {
        $token = Str::random(64);

        return StatusPage::factory()->create([
            'setting_key' => 'default',
            'title' => 'Campaign availability',
            'public_token' => $token,
            'public_token_hash' => hash('sha256', $token),
            'is_published' => true,
        ]);
    }
}
