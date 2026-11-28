<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformAuditAction;
use App\Models\PlatformAuditEvent;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\TwoFactorAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_codes_match_the_rfc_6238_reference_values_and_tolerate_one_step_of_drift(): void
    {
        $authenticator = app(TwoFactorAuthenticator::class);

        $this->assertSame('287082', $authenticator->codeAt(self::RFC_SECRET, 59));
        $this->assertSame('081804', $authenticator->codeAt(self::RFC_SECRET, 1111111109));
        $this->assertSame(1, $authenticator->matchingStep(self::RFC_SECRET, '287082', 59));
        $this->assertSame(1, $authenticator->matchingStep(self::RFC_SECRET, '287 082', 89));
        $this->assertNull($authenticator->matchingStep(self::RFC_SECRET, '287082', 150));
        $this->assertNull($authenticator->matchingStep(self::RFC_SECRET, 'abcdef', 59));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $authenticator->generateSecret());
    }

    public function test_owner_can_set_up_two_factor_and_receives_single_use_recovery_codes(): void
    {
        $owner = $this->customerOwner();
        $owner->forceFill(['remember_token' => 'old-remember-token'])->save();

        $this->actingAs($owner)
            ->post(route('account.two-factor.enable'), ['current_password' => 'wrong-password'])
            ->assertSessionHasErrors('current_password');
        $this->assertNull($owner->refresh()->two_factor_secret);

        $this->post(route('account.two-factor.enable'), ['current_password' => 'customer-password-123'])
            ->assertRedirect(route('account.password.edit'));
        $owner->refresh();
        $this->assertFalse($owner->hasTwoFactorEnabled());
        $this->assertStringNotContainsString($owner->two_factor_secret, (string) DB::table('users')->where('id', $owner->id)->value('two_factor_secret'));

        $this->get(route('account.password.edit'))
            ->assertOk()
            ->assertSee('設定金鑰')
            ->assertSee(app(TwoFactorAuthenticator::class)->formatSecretForDisplay($owner->two_factor_secret));

        $this->post(route('account.two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');

        $response = $this->post(route('account.two-factor.confirm'), ['code' => $this->currentCode($owner)])
            ->assertRedirect(route('account.password.edit'))
            ->assertSessionHas('two_factor_recovery_codes');
        $recoveryCodes = $response->getSession()->get('two_factor_recovery_codes');

        $owner->refresh();
        $this->assertTrue($owner->hasTwoFactorEnabled());
        $this->assertCount(8, $recoveryCodes);
        $this->assertSame(8, $owner->remainingTwoFactorRecoveryCodeCount());
        $this->assertNotContains($recoveryCodes[0], $owner->two_factor_recovery_codes);
        $this->assertNotSame('old-remember-token', $owner->remember_token);
        $this->assertTrue(PlatformAuditEvent::query()->where('action', PlatformAuditAction::TwoFactorEnabled)->where('actor_user_id', $owner->id)->exists());
    }

    public function test_sign_in_requires_a_fresh_authenticator_code_after_the_password(): void
    {
        $owner = $this->customerOwner();
        $this->enableTwoFactor($owner);

        $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'customer-password-123', 'remember' => '1'])
            ->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest('web');
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('two-factor.challenge'))->assertOk()->assertSee('輸入驗證碼');

        $this->post(route('two-factor.challenge.store'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest('web');

        $code = $this->currentCode($owner);
        $this->post(route('two-factor.challenge.store'), ['code' => $code])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($owner, 'web');

        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'customer-password-123']);
        $this->post(route('two-factor.challenge.store'), ['code' => $code])
            ->assertSessionHasErrors(['code' => '驗證碼不正確或已使用過，請等待下一組驗證碼。']);
        $this->assertGuest('web');
    }

    public function test_recovery_codes_work_once_and_the_pending_sign_in_expires(): void
    {
        $owner = $this->customerOwner();
        $recoveryCodes = $this->enableTwoFactor($owner);

        $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'customer-password-123']);
        $this->post(route('two-factor.challenge.store'), ['recovery_code' => strtoupper($recoveryCodes[0])])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', '已使用一組復原碼登入，剩餘 7 組。復原碼用完前請到帳戶安全重新產生。');
        $this->assertAuthenticatedAs($owner, 'web');

        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'customer-password-123']);
        $this->post(route('two-factor.challenge.store'), ['recovery_code' => $recoveryCodes[0]])
            ->assertSessionHasErrors('recovery_code');

        $this->travel(6)->minutes();
        $this->post(route('two-factor.challenge.store'), ['recovery_code' => $recoveryCodes[1]])
            ->assertRedirect(route('login'));
        $this->assertGuest('web');
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    public function test_repeated_wrong_codes_are_rate_limited(): void
    {
        $owner = $this->customerOwner();
        $this->enableTwoFactor($owner);
        $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'customer-password-123']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('two-factor.challenge.store'), ['code' => '000000']);
        }

        $this->post(route('two-factor.challenge.store'), ['code' => $this->currentCode($owner)])
            ->assertSessionHasErrors(['code' => '驗證嘗試次數過多，請於一分鐘後再試。']);
        $this->assertGuest('web');
    }

    public function test_disabling_needs_the_password_and_read_only_members_manage_their_own_account(): void
    {
        $owner = $this->customerOwner();
        $member = User::factory()->create(['password' => Hash::make('member-password-123')]);
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
        ]);

        $this->actingAs($member)
            ->post(route('account.two-factor.enable'), ['current_password' => 'member-password-123'])
            ->assertRedirect(route('account.password.edit'));
        $this->post(route('account.two-factor.confirm'), ['code' => $this->currentCode($member->refresh())])
            ->assertRedirect(route('account.password.edit'));
        $this->assertTrue($member->refresh()->hasTwoFactorEnabled());

        $this->delete(route('account.two-factor.disable'), ['current_password' => 'wrong-password'])
            ->assertSessionHasErrors('current_password');
        $this->assertTrue($member->refresh()->hasTwoFactorEnabled());

        $this->delete(route('account.two-factor.disable'), ['current_password' => 'member-password-123'])
            ->assertRedirect(route('account.password.edit'));
        $member->refresh();
        $this->assertFalse($member->hasTwoFactorEnabled());
        $this->assertNull($member->two_factor_secret);
        $this->assertTrue(PlatformAuditEvent::query()->where('action', PlatformAuditAction::TwoFactorDisabled)->exists());
    }

    public function test_platform_console_sign_in_also_requires_the_code(): void
    {
        $administrator = User::factory()->create(['password' => Hash::make('admin-password-123')]);
        $administrator->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($administrator, 'platform')
            ->get(route('platform.account.security'))
            ->assertOk()
            ->assertSee('尚未啟用');
        $this->post(route('platform.account.two-factor.enable'), ['current_password' => 'admin-password-123'])
            ->assertRedirect(route('platform.account.security'));
        $this->post(route('platform.account.two-factor.confirm'), ['code' => $this->currentCode($administrator->refresh())])
            ->assertRedirect(route('platform.account.security'));
        $this->post(route('platform.logout'));
        $this->travel(1)->minutes();

        $this->post(route('platform.login.store'), ['email' => $administrator->email, 'password' => 'admin-password-123'])
            ->assertRedirect(route('platform.two-factor.challenge'));
        $this->assertGuest('platform');
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));

        $this->post(route('platform.two-factor.challenge.store'), ['code' => $this->currentCode($administrator->refresh())])
            ->assertRedirect(route('platform.index'));
        $this->assertAuthenticatedAs($administrator, 'platform');
        $this->assertGuest('web');
    }

    public function test_reset_command_turns_off_two_factor_for_a_locked_out_account(): void
    {
        $owner = $this->customerOwner();
        $this->enableTwoFactor($owner);

        $this->artisan('users:reset-two-factor', ['email' => strtoupper($owner->email)])->assertExitCode(0);

        $this->assertFalse($owner->refresh()->hasTwoFactorEnabled());
        $this->assertTrue(PlatformAuditEvent::query()->where('action', PlatformAuditAction::TwoFactorReset)->exists());
        $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'customer-password-123'])
            ->assertRedirect(route('dashboard'));
        $this->artisan('users:reset-two-factor', ['email' => 'missing@example.com'])->assertExitCode(1);
    }

    /**
     * @return list<string>
     */
    private function enableTwoFactor(User $user): array
    {
        $authenticator = app(TwoFactorAuthenticator::class);
        $recoveryCodes = $authenticator->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $authenticator->generateSecret(),
            'two_factor_recovery_codes' => $authenticator->hashRecoveryCodes($recoveryCodes),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $recoveryCodes;
    }

    private function currentCode(User $user): string
    {
        return app(TwoFactorAuthenticator::class)->codeAt((string) $user->two_factor_secret, now()->getTimestamp());
    }

    private function customerOwner(): User
    {
        $owner = User::factory()->create(['password' => Hash::make('customer-password-123')]);
        Organization::factory()->create(['owner_user_id' => $owner->id]);

        return $owner;
    }
}
