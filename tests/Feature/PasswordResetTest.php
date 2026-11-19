<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_RESET_MESSAGE = '如果這個電子郵件對應監測台帳戶，我們會寄送重設密碼連結。請查看收件匣與垃圾郵件。';

    public function test_login_page_links_to_the_reset_form_and_invalid_addresses_are_rejected(): void
    {
        Notification::fake();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('忘記密碼？')
            ->assertSee(route('password.request'), false);

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'not-an-email'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors('email');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_guest_can_request_a_reset_link_and_reset_password_once(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'manager@example.test',
            'password' => 'old-password-value',
            'remember_token' => 'old-remember-token',
        ]);

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('忘記密碼')
            ->assertSee('autocomplete="email"', false);

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'Manager@example.test'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', self::GENERIC_RESET_MESSAGE);

        $resetToken = null;
        Notification::assertSentTo($user, PasswordResetNotification::class, function (PasswordResetNotification $notification) use (&$resetToken): bool {
            $resetToken = $notification->token;

            return true;
        });
        $this->assertIsString($resetToken);
        $resetMessage = (new PasswordResetNotification($resetToken))->toMail($user);
        $this->assertSame('重設監測台密碼', $resetMessage->subject);
        $this->assertSame('設定新密碼', $resetMessage->actionText);
        $this->assertStringContainsString($resetToken, (string) $resetMessage->actionUrl);
        $this->assertContains('如果您沒有提出這項要求，請忽略這封郵件；目前密碼不會變更。', $resetMessage->outroLines);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);

        $this->get(route('password.reset', ['token' => $resetToken, 'email' => $user->email]))
            ->assertOk()
            ->assertSee('設定新密碼')
            ->assertSee('autocomplete="new-password"', false)
            ->assertSee('manager@example.test');

        Event::fake([PasswordReset::class]);
        $newPassword = 'correct-horse-battery';

        $this->post(route('password.update'), [
            'token' => $resetToken,
            'email' => 'Manager@example.test',
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertRedirect(route('login'))
            ->assertSessionHas('status', '密碼已重設，請使用新密碼登入。');

        $user->refresh();
        $this->assertTrue(Hash::check($newPassword, $user->password));
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        Event::assertDispatched(PasswordReset::class);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => $newPassword,
        ])->assertRedirect(route('dashboard'));
    }

    public function test_reset_request_uses_the_same_message_for_known_and_unknown_emails(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'manager@example.test']);

        $knownResponse = $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', self::GENERIC_RESET_MESSAGE);
        $unknownResponse = $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'missing@example.test'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', self::GENERIC_RESET_MESSAGE);

        $this->assertSame(
            $knownResponse->getSession()->get('status'),
            $unknownResponse->getSession()->get('status'),
        );
        Notification::assertSentToTimes($user, PasswordResetNotification::class, 1);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_reset_link_is_single_use_and_rejects_short_or_mismatched_passwords(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'manager@example.test']);
        $this->post(route('password.email'), ['email' => $user->email]);

        $resetToken = null;
        Notification::assertSentTo($user, PasswordResetNotification::class, function (PasswordResetNotification $notification) use (&$resetToken): bool {
            $resetToken = $notification->token;

            return true;
        });
        $originalPassword = $user->password;

        $this->from(route('password.reset', ['token' => $resetToken, 'email' => $user->email]))
            ->post(route('password.update'), [
                'token' => $resetToken,
                'email' => $user->email,
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertRedirect(route('password.reset', ['token' => $resetToken, 'email' => $user->email]))
            ->assertSessionHasErrors(['password']);

        $this->assertSame($originalPassword, $user->fresh()->password);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);

        $newPassword = 'a-safe-long-password';
        $this->post(route('password.update'), [
            'token' => $resetToken,
            'email' => $user->email,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertRedirect(route('login'));

        $passwordAfterReset = $user->fresh()->password;
        $this->post(route('password.update'), [
            'token' => $resetToken,
            'email' => $user->email,
            'password' => 'another-safe-long-password',
            'password_confirmation' => 'another-safe-long-password',
        ])->assertRedirect(route('password.reset', ['token' => $resetToken, 'email' => $user->email]))
            ->assertSessionHasErrors('email');

        $this->assertSame($passwordAfterReset, $user->fresh()->password);
    }

    public function test_password_reset_routes_are_guest_only_and_submission_is_rate_limited(): void
    {
        $operator = User::factory()->create();

        $this->actingAs($operator)
            ->get(route('password.request'))
            ->assertRedirect(route('dashboard'));
        $this->get(route('password.reset', ['token' => Str::random(40)]))
            ->assertRedirect(route('dashboard'));

        auth()->logout();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from(route('password.request'))
                ->post(route('password.email'), ['email' => 'missing@example.test'])
                ->assertRedirect(route('password.request'));
        }

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'missing@example.test'])
            ->assertTooManyRequests();
    }
}
