<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformConsoleSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_signs_in_through_the_console_only(): void
    {
        $administrator = $this->platformAdmin();

        $this->post(route('login.store'), ['email' => $administrator->email, 'password' => 'admin-password-123'])
            ->assertSessionHasErrors(['email' => '平台管理員帳號請由管理後台登入。']);
        $this->assertGuest('web');

        $this->post(route('platform.login.store'), ['email' => $administrator->email, 'password' => 'admin-password-123'])
            ->assertRedirect(route('platform.index'));
        $this->assertAuthenticatedAs($administrator, 'platform');
        $this->assertGuest('web');

        $this->get(route('platform.index'))
            ->assertOk()
            ->assertSee('管理後台')
            ->assertDontSee(route('monitors.index'));

        $this->post(route('platform.logout'))->assertRedirect(route('platform.login'));
        $this->assertGuest('platform');
    }

    public function test_customer_credentials_are_rejected_by_the_console_without_revealing_the_account(): void
    {
        $owner = $this->customerOwner();

        $this->post(route('platform.login.store'), ['email' => $owner->email, 'password' => 'customer-password-123'])
            ->assertSessionHasErrors(['email' => '電子郵件或密碼不正確。']);

        $this->assertGuest('platform');
        $this->assertGuest('web');
    }

    public function test_customer_session_cannot_open_the_console(): void
    {
        $owner = $this->customerOwner();

        $this->actingAs($owner, 'web')
            ->get(route('platform.index'))
            ->assertRedirect(route('platform.login'));
        $this->get(route('platform.system.index'))->assertRedirect(route('platform.login'));

        $this->get('/platform')->assertRedirect('/admin');
    }

    public function test_platform_admin_session_on_the_customer_guard_is_signed_out(): void
    {
        $this->actingAs($this->platformAdmin(), 'web')
            ->get(route('dashboard'))
            ->assertRedirect(route('platform.login'));

        $this->assertGuest('web');
    }

    public function test_each_sign_in_ignores_an_intended_url_from_the_other_area(): void
    {
        $owner = $this->customerOwner();
        $administrator = $this->platformAdmin();

        $this->withSession(['url.intended' => url('/admin/system')])
            ->post(route('login.store'), ['email' => $owner->email, 'password' => 'customer-password-123'])
            ->assertRedirect(route('dashboard'));

        $this->withSession(['url.intended' => route('monitors.index')])
            ->post(route('platform.login.store'), ['email' => $administrator->email, 'password' => 'admin-password-123'])
            ->assertRedirect(route('platform.index'));

        $this->withSession(['url.intended' => url('/admin/system')])
            ->post(route('platform.logout'));
        $this->withSession(['url.intended' => url('/admin/system')])
            ->post(route('platform.login.store'), ['email' => $administrator->email, 'password' => 'admin-password-123'])
            ->assertRedirect(url('/admin/system'));
    }

    public function test_customer_layout_has_no_console_links(): void
    {
        $this->actingAs($this->customerOwner(), 'web')
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('platform.index'))
            ->assertDontSee('平台管理員');
    }

    private function platformAdmin(): User
    {
        $administrator = User::factory()->create(['password' => Hash::make('admin-password-123')]);
        $administrator->forceFill(['is_platform_admin' => true])->save();

        return $administrator;
    }

    private function customerOwner(): User
    {
        $owner = User::factory()->create(['password' => Hash::make('customer-password-123')]);
        Organization::factory()->create(['owner_user_id' => $owner->id]);

        return $owner;
    }
}
