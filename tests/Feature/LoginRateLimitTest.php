<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_five_failed_logins_block_the_same_email_and_ip_until_the_window_expires(): void
    {
        $user = User::factory()->create([
            'email' => 'manager@example.test',
            'password' => 'correct-horse-battery',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from(route('login'))
                ->post(route('login.store'), [
                    'email' => $user->email,
                    'password' => 'incorrect-password',
                ])
                ->assertRedirect(route('login'))
                ->assertSessionHasErrors(['email' => '電子郵件或密碼不正確。']);
        }

        $this->from(route('login'))
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'correct-horse-battery',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => '登入嘗試次數過多，請於一分鐘後再試。']);

        $this->travel(61)->seconds();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_failed_attempts_for_one_account_do_not_block_another_account_on_the_same_ip(): void
    {
        $firstUser = User::factory()->create([
            'email' => 'first@example.test',
            'password' => 'first-correct-password',
        ]);
        $secondUser = User::factory()->create([
            'email' => 'second@example.test',
            'password' => 'second-correct-password',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from(route('login'))
                ->post(route('login.store'), [
                    'email' => $firstUser->email,
                    'password' => 'incorrect-password',
                ])
                ->assertRedirect(route('login'));
        }

        $this->post(route('login.store'), [
            'email' => $secondUser->email,
            'password' => 'second-correct-password',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_successful_login_clears_that_accounts_failed_attempts(): void
    {
        $user = User::factory()->create([
            'email' => 'manager@example.test',
            'password' => 'correct-horse-battery',
        ]);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->from(route('login'))
                ->post(route('login.store'), [
                    'email' => $user->email,
                    'password' => 'incorrect-password',
                ])
                ->assertRedirect(route('login'));
        }

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
        ])->assertRedirect(route('dashboard'));

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->from(route('login'))
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'incorrect-password',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => '電子郵件或密碼不正確。']);
    }

    public function test_login_endpoint_keeps_a_per_ip_request_limit(): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->from(route('login'))
                ->post(route('login.store'), [])
                ->assertRedirect(route('login'));
        }

        $this->from(route('login'))
            ->post(route('login.store'), [])
            ->assertTooManyRequests();
    }
}
