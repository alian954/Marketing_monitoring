<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_update_password_and_old_login_no_longer_works(): void
    {
        $currentPassword = 'current-account-password';
        $newPassword = 'new-secure-account-password';
        $user = User::factory()->create([
            'email' => 'manager@example.test',
            'password' => $currentPassword,
            'remember_token' => 'original-remember-token',
        ]);

        $this->actingAs($user)
            ->get(route('account.password.edit'))
            ->assertOk()
            ->assertSee('登入密碼與雙因素驗證')
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee('autocomplete="new-password"', false)
            ->assertSee(route('account.password.update'), false);

        $this->from(route('account.password.edit'))
            ->put(route('account.password.update'), [
                'current_password' => $currentPassword,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ])
            ->assertRedirect(route('account.password.edit'))
            ->assertSessionHas('status', '密碼已更新。舊的「保持登入」憑證已失效，目前瀏覽器仍保持登入。');

        $user->refresh();
        $this->assertTrue(Hash::check($newPassword, $user->password));
        $this->assertNotSame('original-remember-token', $user->remember_token);
        $this->get(route('dashboard'))->assertOk();

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->from(route('login'))
            ->post(route('login.store'), ['email' => $user->email, 'password' => $currentPassword])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->post(route('login.store'), ['email' => $user->email, 'password' => $newPassword])
            ->assertRedirect(route('dashboard'));
    }

    public function test_password_change_rejects_wrong_current_short_unconfirmed_and_reused_passwords(): void
    {
        $currentPassword = 'current-account-password';
        $user = User::factory()->create(['password' => $currentPassword]);
        $this->actingAs($user);
        $originalPasswordHash = $user->password;
        $originalRememberToken = $user->remember_token;
        $route = route('account.password.update');
        $validNewPassword = 'different-secure-password';

        $this->from(route('account.password.edit'))
            ->put($route, [
                'current_password' => 'incorrect-current-password',
                'password' => $validNewPassword,
                'password_confirmation' => $validNewPassword,
            ])
            ->assertSessionHasErrors('current_password');

        $this->from(route('account.password.edit'))
            ->put($route, [
                'current_password' => $currentPassword,
                'password' => 'too-short',
                'password_confirmation' => 'too-short',
            ])
            ->assertSessionHasErrors('password');

        $this->from(route('account.password.edit'))
            ->put($route, [
                'current_password' => $currentPassword,
                'password' => $validNewPassword,
                'password_confirmation' => 'a-different-confirmation',
            ])
            ->assertSessionHasErrors('password');

        $this->from(route('account.password.edit'))
            ->put($route, [
                'current_password' => $currentPassword,
                'password' => $currentPassword,
                'password_confirmation' => $currentPassword,
            ])
            ->assertSessionHasErrors('password');

        $this->assertSame($originalPasswordHash, $user->fresh()->password);
        $this->assertSame($originalRememberToken, $user->fresh()->remember_token);
    }

    public function test_password_change_requires_an_authenticated_user(): void
    {
        $this->get(route('account.password.edit'))->assertRedirect(route('login'));
        $this->put(route('account.password.update'), [
            'current_password' => 'current-account-password',
            'password' => 'new-secure-account-password',
            'password_confirmation' => 'new-secure-account-password',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('users', 0);
    }
}
