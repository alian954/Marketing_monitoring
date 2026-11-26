<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Models\Client;
use App\Models\GoogleAdsConnection;
use App\Models\GoogleAnalyticsConnection;
use App\Models\GoogleSearchConsoleConnection;
use App\Models\MetaMarketingConnection;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Notifications\PasswordResetNotification;
use App\Services\RunScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlatformOrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_platform_admins_can_manage_organizations_and_can_create_a_customer_account(): void
    {
        $customerOwner = User::factory()->create();
        Organization::factory()->create(['owner_user_id' => $customerOwner->id]);

        $this->actingAs($customerOwner)
            ->get(route('platform.index'))
            ->assertForbidden();
        $this->post(route('platform.organizations.store'), [])->assertForbidden();

        $platformAdmin = $this->createPlatformAdmin();

        $this->actingAs($platformAdmin)
            ->post(route('platform.organizations.store'), [
                'name' => '北區行銷代理商',
                'owner_name' => '客戶負責人',
                'owner_email' => 'agency-owner@example.test',
                'owner_password' => 'new-customer-password-123',
                'owner_password_confirmation' => 'new-customer-password-123',
                'subscription_ends_on' => '2027-12-31',
                'seat_limit' => 3,
                'plan_name' => '代理商試用',
                'client_limit' => 5,
                'monitor_limit' => 25,
                'campaign_limit' => 10,
            ])
            ->assertRedirect(route('platform.index'))
            ->assertSessionHas('status', '組織與負責人帳號已建立。');

        $newOwner = User::query()->where('email', 'agency-owner@example.test')->firstOrFail();
        $organization = Organization::query()->where('owner_user_id', $newOwner->id)->firstOrFail();

        $this->assertSame('客戶負責人', $newOwner->name);
        $this->assertTrue(Hash::check('new-customer-password-123', $newOwner->password));
        $this->assertFalse($newOwner->isPlatformAdmin());
        $this->assertSame('北區行銷代理商', $organization->name);
        $this->assertSame('2027-12-31', $organization->subscription_ends_on?->toDateString());
        $this->assertSame(3, $organization->seat_limit);
        $this->assertSame('代理商試用', $organization->plan_name);
        $this->assertSame(5, $organization->client_limit);
        $this->assertSame(25, $organization->monitor_limit);
        $this->assertSame(10, $organization->campaign_limit);
        $this->assertTrue($organization->is_enabled);

        $this->actingAs($platformAdmin)
            ->patch(route('platform.organizations.update', $organization), [
                'name' => '北區行銷代理商',
                'subscription_ends_on' => '2000-01-01',
                'seat_limit' => 5,
                'plan_name' => '代理商年度方案',
                'client_limit' => 20,
                'monitor_limit' => 100,
                'campaign_limit' => 50,
                'is_enabled' => true,
            ])
            ->assertRedirect(route('platform.index'));

        $organization->refresh();
        $this->assertFalse($organization->isAccessible());
        $this->assertSame(5, $organization->fresh()->seat_limit);
        $this->assertSame('代理商年度方案', $organization->fresh()->plan_name);
        $this->assertSame(20, $organization->fresh()->client_limit);
        $this->assertSame(100, $organization->fresh()->monitor_limit);
        $this->assertSame(50, $organization->fresh()->campaign_limit);

        $this->actingAs($platformAdmin->fresh())
            ->get(route('platform.index'))
            ->assertOk()
            ->assertSee('方案與用量上限')
            ->assertSee('代理商年度方案')
            ->assertSee('0 / 20')
            ->assertSee('0 / 100')
            ->assertSee('0 / 50');
        $this->assertSame('訂閱已到期', $organization->statusLabel());

        $this->actingAs($platformAdmin->fresh())
            ->patch(route('platform.organizations.update', $organization), [
                'name' => '北區行銷代理商',
                'subscription_ends_on' => '2027-12-31',
                'is_enabled' => false,
            ])
            ->assertRedirect(route('platform.index'));

        $this->assertFalse($organization->fresh()->isAccessible());
        $this->assertSame('已手動停用', $organization->fresh()->statusLabel());
        $this->assertSame('代理商年度方案', $organization->fresh()->plan_name);
        $this->assertSame(20, $organization->fresh()->client_limit);
        $this->assertSame(100, $organization->fresh()->monitor_limit);
        $this->assertSame(50, $organization->fresh()->campaign_limit);
    }

    public function test_platform_admin_must_name_the_plan_when_creating_an_organization(): void
    {
        $platformAdmin = $this->createPlatformAdmin();

        $this->actingAs($platformAdmin)
            ->from(route('platform.index'))
            ->post(route('platform.organizations.store'), [
                'name' => '待設定方案的組織',
                'owner_name' => '組織負責人',
                'owner_email' => 'unnamed-plan@example.test',
                'owner_password' => 'customer-password-123',
                'owner_password_confirmation' => 'customer-password-123',
            ])
            ->assertSessionHasErrors(['plan_name' => '請設定組織方案名稱。']);

        $this->assertDatabaseMissing('users', ['email' => 'unnamed-plan@example.test']);
    }

    public function test_usage_limit_validation_is_associated_with_the_invalid_field(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->for($owner, 'owner')->create([
            'plan_name' => '代理商試用',
            'client_limit' => 5,
            'monitor_limit' => 25,
            'campaign_limit' => 10,
        ]);
        $platformAdmin = $this->createPlatformAdmin();

        $this->actingAs($platformAdmin)
            ->from(route('platform.index'))
            ->followingRedirects()
            ->patch(route('platform.organizations.update', $organization), [
                'organization_id' => $organization->id,
                'name' => $organization->name,
                'plan_name' => $organization->plan_name,
                'seat_limit' => null,
                'client_limit' => 0,
                'monitor_limit' => $organization->monitor_limit,
                'campaign_limit' => $organization->campaign_limit,
                'is_enabled' => true,
            ])
            ->assertOk()
            ->assertSee('客戶上限至少為 1；若不設上限請留空。')
            ->assertSee('id="organization-client-limit-'.$organization->id.'-error"', false)
            ->assertSee('aria-invalid="true"', false);

        $this->assertSame(5, $organization->fresh()->client_limit);
    }

    public function test_platform_admin_can_manage_seat_limits_and_sees_reserved_invitation_usage(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->for($owner, 'owner')->create(['seat_limit' => 3]);
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'accepted_at' => now(),
        ]);
        $owner->workspaceInvitations()->create([
            'email' => 'pending-platform-seat@example.test',
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
            'token_hash' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addDay(),
        ]);
        $platformAdmin = $this->createPlatformAdmin();

        $response = $this->actingAs($platformAdmin)
            ->get(route('platform.index'))
            ->assertOk()
            ->assertSee('3 / 3')
            ->assertSee('1 個待接受邀請');

        $organizationRow = collect($response->viewData('organizationRows'))
            ->firstWhere('organization.id', $organization->id);
        $this->assertSame(3, $organizationRow['occupied_seat_count']);

        $this->from(route('platform.index'))
            ->followingRedirects()
            ->patch(route('platform.organizations.update', $organization), [
                'organization_id' => $organization->id,
                'name' => $organization->name,
                'subscription_ends_on' => null,
                'seat_limit' => '0',
                'is_enabled' => true,
            ])
            ->assertOk()
            ->assertSee('席次上限至少為 1；若不設上限請留空。')
            ->assertSee('aria-invalid="true"', false);

        $this->assertSame(3, $organization->fresh()->seat_limit);
    }

    #[DataProvider('invalidUsageLimits')]
    public function test_platform_admin_cannot_set_a_nonpositive_usage_limit(string $field, string $message): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->for($owner, 'owner')->create([
            'plan_name' => '代理商試用',
            'client_limit' => 5,
            'monitor_limit' => 25,
            'campaign_limit' => 10,
        ]);
        $platformAdmin = $this->createPlatformAdmin();
        $payload = [
            'name' => $organization->name,
            'plan_name' => $organization->plan_name,
            'seat_limit' => null,
            'client_limit' => $organization->client_limit,
            'monitor_limit' => $organization->monitor_limit,
            'campaign_limit' => $organization->campaign_limit,
            'is_enabled' => true,
            $field => 0,
        ];

        $this->actingAs($platformAdmin)
            ->patch(route('platform.organizations.update', $organization), $payload)
            ->assertSessionHasErrors([$field => $message]);

        $this->assertSame(5, $organization->fresh()->client_limit);
        $this->assertSame(25, $organization->fresh()->monitor_limit);
        $this->assertSame(10, $organization->fresh()->campaign_limit);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidUsageLimits(): array
    {
        return [
            'clients' => ['client_limit', '客戶上限至少為 1；若不設上限請留空。'],
            'monitors' => ['monitor_limit', '監測項目上限至少為 1；若不設上限請留空。'],
            'campaigns' => ['campaign_limit', '活動上限至少為 1；若不設上限請留空。'],
        ];
    }

    public function test_organization_migration_does_not_promote_an_arbitrary_legacy_owner_when_candidates_are_ambiguous(): void
    {
        $firstOwner = User::factory()->create(['name' => '第一個工作區擁有人']);
        $secondOwner = User::factory()->create(['name' => '第二個工作區擁有人']);
        $workspaceMember = User::factory()->create(['name' => '既有工作區成員']);
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $firstOwner->id,
            'member_user_id' => $workspaceMember->id,
        ]);

        $this->runLegacyOrganizationMigrations();

        $this->assertDatabaseHas('organizations', [
            'owner_user_id' => $firstOwner->id,
            'name' => '第一個工作區擁有人',
            'seat_limit' => null,
            'plan_name' => null,
            'client_limit' => null,
            'monitor_limit' => null,
            'campaign_limit' => null,
        ]);
        $this->assertDatabaseHas('organizations', [
            'owner_user_id' => $secondOwner->id,
            'name' => '第二個工作區擁有人',
            'seat_limit' => null,
            'plan_name' => null,
            'client_limit' => null,
            'monitor_limit' => null,
            'campaign_limit' => null,
        ]);
        $this->assertDatabaseMissing('organizations', ['owner_user_id' => $workspaceMember->id]);
        $this->assertDatabaseHas('users', ['id' => $firstOwner->id, 'is_platform_admin' => false]);
        $this->assertDatabaseHas('users', ['id' => $secondOwner->id, 'is_platform_admin' => false]);
        $this->assertDatabaseHas('users', ['id' => $workspaceMember->id, 'is_platform_admin' => false]);

        $this->artisan('monitor:create-manager')
            ->expectsQuestion('管理者名稱', '明確建立的平台管理員')
            ->expectsQuestion('管理者電子郵件', 'platform-admin@example.test')
            ->expectsQuestion('密碼（至少 12 個字元）', 'platform-admin-password-123')
            ->expectsQuestion('再次輸入密碼', 'platform-admin-password-123')
            ->expectsOutput('已建立總管理員帳號，公開註冊功能已關閉。')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => 'platform-admin@example.test',
            'is_platform_admin' => true,
        ]);
    }

    public function test_organization_migration_preserves_the_sole_legacy_manager_and_maps_members_to_that_owner(): void
    {
        $owner = User::factory()->create(['name' => '唯一工作區擁有人']);
        $workspaceMember = User::factory()->create(['name' => '既有工作區成員']);
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $workspaceMember->id,
        ]);

        $this->runLegacyOrganizationMigrations();

        $this->assertDatabaseHas('organizations', [
            'owner_user_id' => $owner->id,
            'name' => '唯一工作區擁有人',
            'seat_limit' => null,
            'plan_name' => null,
            'client_limit' => null,
            'monitor_limit' => null,
            'campaign_limit' => null,
        ]);
        $this->assertDatabaseMissing('organizations', ['owner_user_id' => $workspaceMember->id]);
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'is_platform_admin' => true]);
        $this->assertDatabaseHas('users', ['id' => $workspaceMember->id, 'is_platform_admin' => false]);
    }

    public function test_platform_admin_context_is_tenant_scoped_and_cannot_be_forged_by_a_customer(): void
    {
        $firstOwner = User::factory()->create();
        $firstOrganization = Organization::factory()->create([
            'owner_user_id' => $firstOwner->id,
            'name' => '第一家代理商',
        ]);
        $firstClient = $firstOwner->clients()->create(['name' => '第一家客戶']);

        $secondOwner = User::factory()->create();
        $secondOrganization = Organization::factory()->create([
            'owner_user_id' => $secondOwner->id,
            'name' => '第二家代理商',
        ]);
        $secondClient = $secondOwner->clients()->create(['name' => '第二家客戶']);

        $this->actingAs($firstOwner)
            ->withSession(['platform_organization_id' => $secondOrganization->id])
            ->get(route('clients.index'))
            ->assertOk()
            ->assertSee($firstClient->name)
            ->assertDontSee($secondClient->name);

        $this->get(route('platform.index'))->assertForbidden();

        $platformAdmin = $this->createPlatformAdmin();
        $this->actingAs($platformAdmin)
            ->post(route('platform.organizations.open', $firstOrganization))
            ->assertRedirect(route('dashboard'));

        $this->get(route('clients.index'))
            ->assertOk()
            ->assertSee($firstClient->name)
            ->assertDontSee($secondClient->name);

        $this->post(route('platform.organizations.leave'))
            ->assertRedirect(route('platform.index'))
            ->assertSessionMissing('platform_organization_id');

        $this->actingAs($platformAdmin->fresh())
            ->get(route('clients.index'))
            ->assertOk()
            ->assertDontSee($firstClient->name)
            ->assertDontSee($secondClient->name);
    }

    public function test_workspace_member_is_limited_to_assigned_clients_and_other_organizations(): void
    {
        $owner = User::factory()->create();
        Organization::factory()->create(['owner_user_id' => $owner->id]);
        $assignedClient = $owner->clients()->create(['name' => '授權客戶']);
        $unassignedClient = $owner->clients()->create(['name' => '未授權客戶']);

        $otherOwner = User::factory()->create();
        Organization::factory()->create(['owner_user_id' => $otherOwner->id]);
        $otherOrganizationClient = $otherOwner->clients()->create(['name' => '其他組織客戶']);

        $member = User::factory()->create();
        $membership = WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_EDITOR,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_SELECTED,
        ]);
        $membership->clients()->sync([$assignedClient->id]);

        $this->actingAs($member)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertSee($assignedClient->name)
            ->assertDontSee($unassignedClient->name)
            ->assertDontSee($otherOrganizationClient->name);

        $this->put(route('clients.update', $unassignedClient), ['name' => '越權修改'])
            ->assertNotFound();
        $this->put(route('clients.update', $otherOrganizationClient), ['name' => '跨組織修改'])
            ->assertNotFound();

        $this->assertSame($owner->id, $member->workspaceOwnerId());
        $this->assertSame(WorkspaceMembership::ROLE_EDITOR, $member->workspaceMembership?->role);
    }

    public function test_platform_admin_can_open_and_reactivate_an_expired_organization(): void
    {
        $owner = User::factory()->create(['password' => Hash::make('customer-password-123')]);
        $organization = Organization::factory()->create([
            'owner_user_id' => $owner->id,
            'subscription_ends_on' => '2000-01-01',
            'is_enabled' => true,
        ]);
        $platformAdmin = $this->createPlatformAdmin();

        $this->actingAs($platformAdmin)
            ->post(route('platform.organizations.open', $organization))
            ->assertRedirect(route('dashboard'));

        $this->actingAs($platformAdmin->fresh())
            ->get(route('dashboard'))
            ->assertOk();

        $this->actingAs($platformAdmin->fresh())
            ->patch(route('platform.organizations.update', $organization), [
                'name' => '已恢復服務的組織',
                'subscription_ends_on' => now('Asia/Taipei')->addDay()->toDateString(),
                'is_enabled' => true,
            ])
            ->assertRedirect(route('platform.index'));

        $this->assertTrue($organization->fresh()->isAccessible());
        $this->assertSame('已恢復服務的組織', $organization->fresh()->name);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('login.store'), [
            'email' => $owner->email,
            'password' => 'customer-password-123',
        ])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($owner);
        $this->get(route('dashboard'))->assertOk();
    }

    #[DataProvider('inactiveOrganizationSettings')]
    public function test_disabled_or_expired_organizations_block_login_and_end_existing_sessions(
        bool $isEnabled,
        ?string $subscriptionEndsOn,
    ): void {
        $owner = User::factory()->create(['password' => Hash::make('customer-password-123')]);
        Organization::factory()->create([
            'owner_user_id' => $owner->id,
            'is_enabled' => $isEnabled,
            'subscription_ends_on' => $subscriptionEndsOn,
        ]);

        $this->from(route('login'))->post(route('login.store'), [
            'email' => $owner->email,
            'password' => 'customer-password-123',
        ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');
        $this->assertGuest();
    }

    public function test_organization_expires_at_the_end_of_its_date_in_taipei_time(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create([
            'owner_user_id' => $owner->getKey(),
            'is_enabled' => true,
            'subscription_ends_on' => '2026-11-25',
        ]);
        $lastDayMonitor = $this->createOwnedMonitor($owner, 'https://expiry-boundary.example/last-day');
        $expiredMonitor = $this->createOwnedMonitor($owner, 'https://expiry-boundary.example/next-day');
        Queue::fake();

        $this->travelTo(CarbonImmutable::parse('2026-11-25 23:59:59', 'Asia/Taipei'));
        $this->assertTrue($organization->isAccessible());
        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
        $lastDayRun = app(RunScheduler::class)->enqueue($lastDayMonitor, 'manual');
        $this->assertNotNull($lastDayRun);

        $this->travelTo(CarbonImmutable::parse('2026-11-26 00:00:00', 'Asia/Taipei'));
        $this->assertFalse($organization->fresh()->isAccessible());
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');
        $this->assertGuest();
        $this->assertNull(app(RunScheduler::class)->enqueue($expiredMonitor, 'manual'));

        $this->assertDatabaseHas('check_runs', [
            'id' => $lastDayRun->getKey(),
            'status' => 'queued',
        ]);
        $this->assertDatabaseMissing('check_runs', ['monitor_id' => $expiredMonitor->getKey()]);
        Queue::assertPushed(RunMonitorCheck::class, 1);
    }

    #[DataProvider('inactiveOrganizationSettings')]
    public function test_disabled_or_expired_organizations_reject_invitation_acceptance(
        bool $isEnabled,
        ?string $subscriptionEndsOn,
    ): void {
        $owner = User::factory()->create();
        Organization::factory()->create([
            'owner_user_id' => $owner->id,
            'is_enabled' => $isEnabled,
            'subscription_ends_on' => $subscriptionEndsOn,
        ]);
        $token = Str::random(64);
        WorkspaceInvitation::factory()->create([
            'owner_user_id' => $owner->id,
            'email' => 'invited-member@example.test',
            'token_hash' => hash('sha256', $token),
        ]);

        $this->get(route('team.invitations.show', ['token' => $token]))->assertNotFound();
        $this->post(route('team.invitations.accept', ['token' => $token]), [
            'name' => '受邀成員',
            'password' => 'invited-member-password-123',
            'password_confirmation' => 'invited-member-password-123',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'invited-member@example.test']);
        $this->assertDatabaseMissing('workspace_memberships', ['owner_user_id' => $owner->id]);
    }

    public function test_password_reset_is_blocked_if_the_organization_is_disabled_after_token_creation(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);

        $this->post(route('password.email'), ['email' => $owner->email])
            ->assertRedirect()
            ->assertSessionHas('status');
        Notification::assertSentTo($owner, PasswordResetNotification::class);

        $token = Password::broker()->createToken($owner);
        $originalPassword = $owner->fresh()->password;
        $organization->update(['is_enabled' => false]);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $owner->email,
            'password' => 'changed-password-123',
            'password_confirmation' => 'changed-password-123',
        ])->assertSessionHasErrors('email');

        $this->assertSame($originalPassword, $owner->fresh()->password);
    }

    public function test_platform_admin_reads_external_connections_from_the_selected_organization_owner(): void
    {
        $organizationOwner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $organizationOwner->id]);
        $otherOwner = User::factory()->create();
        Organization::factory()->create(['owner_user_id' => $otherOwner->id]);
        $platformAdmin = $this->createPlatformAdmin();

        GoogleAnalyticsConnection::query()->create([
            'user_id' => $organizationOwner->id,
            'property_id' => '1010101',
            'refresh_token' => 'organization-refresh-token',
        ]);
        GoogleAnalyticsConnection::query()->create([
            'user_id' => $otherOwner->id,
            'property_id' => '2020202',
            'refresh_token' => 'other-refresh-token',
        ]);
        GoogleAdsConnection::factory()->create([
            'user_id' => $organizationOwner->id,
            'customer_id' => '1112223333',
        ]);
        GoogleAdsConnection::factory()->create([
            'user_id' => $otherOwner->id,
            'customer_id' => '9998887777',
        ]);
        MetaMarketingConnection::factory()->create([
            'user_id' => $organizationOwner->id,
            'ad_account_id' => 'act_11111111',
        ]);
        MetaMarketingConnection::factory()->create([
            'user_id' => $otherOwner->id,
            'ad_account_id' => 'act_99999999',
        ]);
        GoogleSearchConsoleConnection::factory()->create([
            'user_id' => $organizationOwner->id,
            'site_url' => 'https://agency-client.example.test/',
        ]);
        GoogleSearchConsoleConnection::factory()->create([
            'user_id' => $otherOwner->id,
            'site_url' => 'https://other-client.example.test/',
        ]);

        $this->fakeConnectedSiteList();
        $this->actingAs($platformAdmin)->withSession(['platform_organization_id' => $organization->id]);

        $analyticsResponse = $this->get(route('integrations.google-analytics.edit'))->assertOk();
        $this->assertSame($organizationOwner->id, $analyticsResponse->viewData('connection')->user_id);
        $this->assertSame('1010101', $analyticsResponse->viewData('connection')->property_id);

        $googleAdsResponse = $this->get(route('integrations.google-ads.edit'))->assertOk();
        $this->assertSame($organizationOwner->id, $googleAdsResponse->viewData('connection')->user_id);
        $this->assertSame('1112223333', $googleAdsResponse->viewData('connection')->customer_id);

        $metaResponse = $this->get(route('integrations.meta-marketing.edit'))->assertOk();
        $this->assertSame($organizationOwner->id, $metaResponse->viewData('connection')->user_id);
        $this->assertSame('act_11111111', $metaResponse->viewData('connection')->ad_account_id);

        $searchConsoleResponse = $this->get(route('integrations.google-search-console.edit'))->assertOk();
        $this->assertSame($organizationOwner->id, $searchConsoleResponse->viewData('connection')->user_id);
        $this->assertSame('https://agency-client.example.test/', $searchConsoleResponse->viewData('connection')->site_url);
    }

    public function test_oauth_callbacks_store_external_connections_under_the_selected_organization_owner(): void
    {
        config([
            'services.google_analytics.client_id' => 'ga-client-id',
            'services.google_analytics.client_secret' => 'ga-client-secret',
            'services.google_ads.client_id' => 'ads-client-id',
            'services.google_ads.client_secret' => 'ads-client-secret',
            'services.google_search_console.client_id' => 'search-console-client-id',
            'services.google_search_console.client_secret' => 'search-console-client-secret',
            'services.meta_marketing.client_id' => 'meta-client-id',
            'services.meta_marketing.client_secret' => 'meta-client-secret',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'ga-access-token', 'refresh_token' => 'ga-refresh-token'])
                ->push(['access_token' => 'ads-access-token', 'refresh_token' => 'ads-refresh-token'])
                ->push(['access_token' => 'search-console-access-token', 'refresh_token' => 'search-console-refresh-token']),
            'https://graph.facebook.com/v27.0/oauth/access_token*' => Http::sequence()
                ->push(['access_token' => 'meta-short-token', 'expires_in' => 3600])
                ->push(['access_token' => 'meta-access-token', 'expires_in' => 3600]),
        ]);

        $organizationOwner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $organizationOwner->id]);
        $platformAdmin = $this->createPlatformAdmin();

        $this->actingAs($platformAdmin)->withSession([
            'platform_organization_id' => $organization->id,
            'google_analytics.oauth_state' => 'ga-state',
            'google_ads.oauth_state' => 'ads-state',
            'google_search_console.oauth_state' => 'search-console-state',
            'meta_marketing.oauth_state' => 'meta-state',
        ]);

        $this->get(route('integrations.google-analytics.callback', ['state' => 'ga-state', 'code' => 'ga-code']))
            ->assertRedirect(route('integrations.google-analytics.edit'));
        $this->get(route('integrations.google-ads.callback', ['state' => 'ads-state', 'code' => 'ads-code']))
            ->assertRedirect(route('integrations.google-ads.edit'));
        $this->get(route('integrations.meta-marketing.callback', ['state' => 'meta-state', 'code' => 'meta-code']))
            ->assertRedirect(route('integrations.meta-marketing.edit'));
        $this->get(route('integrations.google-search-console.callback', [
            'state' => 'search-console-state',
            'code' => 'search-console-code',
        ]))->assertRedirect(route('integrations.google-search-console.edit'));

        $this->assertSame($organizationOwner->id, GoogleAnalyticsConnection::query()->sole()->user_id);
        $this->assertSame('ga-refresh-token', GoogleAnalyticsConnection::query()->sole()->refresh_token);
        $this->assertSame($organizationOwner->id, GoogleAdsConnection::query()->sole()->user_id);
        $this->assertSame('ads-refresh-token', GoogleAdsConnection::query()->sole()->refresh_token);
        $this->assertSame($organizationOwner->id, MetaMarketingConnection::query()->sole()->user_id);
        $this->assertSame('meta-access-token', MetaMarketingConnection::query()->sole()->access_token);
        $this->assertSame($organizationOwner->id, GoogleSearchConsoleConnection::query()->sole()->user_id);
        $this->assertSame('search-console-refresh-token', GoogleSearchConsoleConnection::query()->sole()->refresh_token);

        $this->assertDatabaseMissing('google_analytics_connections', ['user_id' => $platformAdmin->id]);
        $this->assertDatabaseMissing('google_ads_connections', ['user_id' => $platformAdmin->id]);
        $this->assertDatabaseMissing('meta_marketing_connections', ['user_id' => $platformAdmin->id]);
        $this->assertDatabaseMissing('google_search_console_connections', ['user_id' => $platformAdmin->id]);
    }

    public static function inactiveOrganizationSettings(): array
    {
        return [
            'disabled' => [false, null],
            'expired' => [true, '2000-01-01'],
        ];
    }

    private function createPlatformAdmin(): User
    {
        $platformAdmin = User::factory()->create();
        $platformAdmin->forceFill(['is_platform_admin' => true])->save();

        return $platformAdmin;
    }

    private function createOwnedMonitor(User $owner, string $url): Monitor
    {
        $client = Client::ensureDefaultFor($owner);
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'client_id' => $client->getKey(),
        ]);
        $monitor->forceFill(['user_id' => $owner->getKey()])->save();

        return $monitor;
    }

    private function runLegacyOrganizationMigrations(): void
    {
        Schema::drop('organizations');
        Schema::table('users', static function (Blueprint $table): void {
            $table->dropColumn('is_platform_admin');
        });

        $createOrganizationsMigration = require base_path('database/migrations/2026_11_24_130042_create_organizations_table.php');
        $createOrganizationsMigration->up();
        $addPlatformAdminMigration = require base_path('database/migrations/2026_11_24_130049_add_platform_admin_to_users_table.php');
        $addPlatformAdminMigration->up();
        $addSeatLimitMigration = require base_path('database/migrations/2026_11_24_170653_add_seat_limit_to_organizations_table.php');
        $addSeatLimitMigration->up();
        $addUsageLimitsMigration = require base_path('database/migrations/2026_11_24_172649_add_usage_limits_to_organizations_table.php');
        $addUsageLimitsMigration->up();
    }

    private function fakeConnectedSiteList(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'organization-access-token',
            ]),
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://agency-client.example.test/', 'permissionLevel' => 'siteOwner'],
                    ['siteUrl' => 'https://other-client.example.test/', 'permissionLevel' => 'siteOwner'],
                ],
            ]),
        ]);
    }
}
