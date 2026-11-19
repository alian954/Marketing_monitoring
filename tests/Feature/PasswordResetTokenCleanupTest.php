<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class PasswordResetTokenCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_password_reset_tokens_are_cleared_and_current_tokens_are_kept(): void
    {
        Notification::fake();
        $expiredUser = User::factory()->create(['email' => 'expired@example.test']);
        $currentUser = User::factory()->create(['email' => 'current@example.test']);
        Password::sendResetLink(['email' => $expiredUser->email]);
        Password::sendResetLink(['email' => $currentUser->email]);
        DB::table('password_reset_tokens')
            ->where('email', $expiredUser->email)
            ->update(['created_at' => now()->subMinutes(61)]);

        $this->artisan('auth:clear-resets')->assertExitCode(0);

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $expiredUser->email]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $currentUser->email]);
    }

    public function test_expired_token_cleanup_is_scheduled_every_fifteen_minutes(): void
    {
        $event = collect(Schedule::events())->first(
            static fn (object $event): bool => str_contains((string) $event->command, 'auth:clear-resets'),
        );

        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
    }
}
