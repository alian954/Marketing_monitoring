<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlanEntitlement;
use App\Models\PlatformAuditAction;
use App\Models\PlatformAuditEvent;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\TrialRequest;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\ProductEntitlementService;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrialRequestTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProductCatalogSeeder::class);
        $this->product = Product::query()->where('product_code', 'website-monitoring')->firstOrFail();
        $this->plan = ProductPlan::factory()->for($this->product)->create(['name' => '專業版', 'status' => 'active']);
        PlanEntitlement::query()->create([
            'product_id' => $this->product->getKey(),
            'product_plan_id' => $this->plan->getKey(),
            'feature_key' => 'monitoring.targets',
            'is_enabled' => true,
            'limit_value' => 20,
        ]);
    }

    public function test_organization_owner_can_request_a_trial_for_an_active_plan(): void
    {
        [$owner, $organization] = $this->ownerWithOrganization();

        $this->actingAs($owner)
            ->get(route('trials.index'))
            ->assertOk()
            ->assertSee('網站可靠度監測')
            ->assertSee('專業版');

        $this->actingAs($owner)
            ->post(route('trials.store'), ['product_plan_id' => $this->plan->id, 'message' => '想先試用兩週'])
            ->assertRedirect(route('trials.index'))
            ->assertSessionHasNoErrors();

        $trialRequest = TrialRequest::query()->sole();
        $this->assertSame($organization->id, $trialRequest->organization_id);
        $this->assertSame($this->product->id, $trialRequest->product_id);
        $this->assertSame(TrialRequest::STATUS_PENDING, $trialRequest->status);
        $this->assertSame('想先試用兩週', $trialRequest->message);
        $this->assertSame($owner->id, $trialRequest->requested_by_user_id);
        $this->assertTrue(PlatformAuditEvent::query()->where('action', PlatformAuditAction::TrialRequested)->exists());

        $this->actingAs($owner)->get(route('trials.index'))->assertSee('審核中');
    }

    public function test_owner_cannot_request_the_same_product_twice_while_pending_or_after_subscribing(): void
    {
        [$owner, $organization] = $this->ownerWithOrganization();
        TrialRequest::factory()->create(['organization_id' => $organization->id, 'product_plan_id' => $this->plan->id]);

        $this->actingAs($owner)
            ->post(route('trials.store'), ['product_plan_id' => $this->plan->id])
            ->assertSessionHasErrors(['product_plan_id' => '此產品的試用申請正在審核中。']);

        [$subscribedOwner, $subscribedOrganization] = $this->ownerWithOrganization();
        $subscription = Subscription::factory()->create(['organization_id' => $subscribedOrganization->id]);
        SubscriptionItem::query()->create([
            'organization_id' => $subscribedOrganization->id,
            'subscription_id' => $subscription->id,
            'product_id' => $this->product->id,
            'product_plan_id' => $this->plan->id,
            'status' => 'active',
            'quantity' => 1,
            'starts_at' => now()->subDay(),
        ]);

        $this->actingAs($subscribedOwner)
            ->post(route('trials.store'), ['product_plan_id' => $this->plan->id])
            ->assertSessionHasErrors(['product_plan_id' => '組織已訂閱或試用過此產品。']);

        $this->assertSame(1, TrialRequest::query()->count());
    }

    public function test_plans_that_are_not_active_cannot_be_requested(): void
    {
        [$owner] = $this->ownerWithOrganization();
        $draftPlan = ProductPlan::factory()->for($this->product)->create(['status' => 'draft']);

        $this->actingAs($owner)
            ->post(route('trials.store'), ['product_plan_id' => $draftPlan->id])
            ->assertSessionHasErrors(['product_plan_id' => '請選擇目前開放試用的產品方案。']);

        $this->assertDatabaseCount('trial_requests', 0);
    }

    public function test_workspace_members_and_platform_admins_cannot_submit_trial_requests(): void
    {
        [$owner] = $this->ownerWithOrganization();
        $membership = WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'role' => WorkspaceMembership::ROLE_EDITOR,
        ]);

        $this->actingAs($membership->memberUser)
            ->post(route('trials.store'), ['product_plan_id' => $this->plan->id])
            ->assertForbidden();

        $this->actingAs($this->platformAdmin(), 'platform')
            ->post(route('trials.store'), ['product_plan_id' => $this->plan->id])
            ->assertForbidden();

        $this->assertDatabaseCount('trial_requests', 0);
    }

    public function test_only_platform_admins_can_review_trial_requests(): void
    {
        [$owner, $organization] = $this->ownerWithOrganization();
        $trialRequest = TrialRequest::factory()->create(['organization_id' => $organization->id, 'product_plan_id' => $this->plan->id]);

        $this->actingAs($owner)->get(route('platform.trial-requests.index'))->assertRedirect(route('platform.login'));
        $this->actingAs($owner)
            ->post(route('platform.trial-requests.approve', $trialRequest), ['trial_days' => 14])
            ->assertRedirect(route('platform.login'));

        $this->actingAs($this->platformAdmin(), 'platform')
            ->get(route('platform.trial-requests.index'))
            ->assertOk()
            ->assertSee($organization->name)
            ->assertSee('核准並開通試用');
    }

    public function test_approving_a_trial_grants_access_until_the_trial_ends(): void
    {
        [, $organization] = $this->ownerWithOrganization();
        $organization->forceFill(['entitlements_enforced_at' => now()])->save();
        $trialRequest = TrialRequest::factory()->create(['organization_id' => $organization->id, 'product_plan_id' => $this->plan->id]);
        $admin = $this->platformAdmin();
        $entitlements = app(ProductEntitlementService::class);

        $this->assertFalse($entitlements->resolve($organization, 'website-monitoring', 'monitoring.targets')->isGranted);

        $this->actingAs($admin, 'platform')
            ->post(route('platform.trial-requests.approve', $trialRequest), ['trial_days' => 7, 'review_note' => '歡迎試用'])
            ->assertRedirect(route('platform.trial-requests.index'))
            ->assertSessionHasNoErrors();

        $trialRequest->refresh();
        $this->assertSame(TrialRequest::STATUS_APPROVED, $trialRequest->status);
        $this->assertSame(7, $trialRequest->trial_days);
        $this->assertSame($admin->id, $trialRequest->reviewed_by_user_id);

        $subscription = Subscription::query()->where('organization_id', $organization->id)->sole();
        $this->assertSame($subscription->id, $trialRequest->subscription_id);
        $this->assertSame('trialing', $subscription->status);
        $this->assertTrue($subscription->trial_ends_at->equalTo(now()->addDays(7)->startOfSecond()));
        $this->assertSame($this->plan->id, SubscriptionItem::query()->where('organization_id', $organization->id)->sole()->product_plan_id);

        $grant = $entitlements->resolve($organization, 'website-monitoring', 'monitoring.targets');
        $this->assertTrue($grant->isGranted);
        $this->assertSame(20, $grant->limitValue);
        $this->assertFalse($entitlements->resolve($organization, 'website-monitoring', 'monitoring.targets', now()->addDays(7)->addMinute())->isGranted);
        $this->assertTrue(PlatformAuditEvent::query()->where('action', PlatformAuditAction::TrialApproved)->exists());
    }

    public function test_a_reviewed_request_cannot_be_approved_again(): void
    {
        [, $organization] = $this->ownerWithOrganization();
        $trialRequest = TrialRequest::factory()->rejected()->create(['organization_id' => $organization->id, 'product_plan_id' => $this->plan->id]);

        $this->actingAs($this->platformAdmin(), 'platform')
            ->post(route('platform.trial-requests.approve', $trialRequest), ['trial_days' => 14])
            ->assertSessionHasErrorsIn('trialReview', ['trial_days']);

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_rejecting_requires_a_note_and_the_owner_can_request_again(): void
    {
        [$owner, $organization] = $this->ownerWithOrganization();
        $trialRequest = TrialRequest::factory()->create(['organization_id' => $organization->id, 'product_plan_id' => $this->plan->id]);
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'platform')
            ->post(route('platform.trial-requests.reject', $trialRequest), ['review_note' => ''])
            ->assertSessionHasErrorsIn('trialReview', ['review_note']);
        $this->assertTrue($trialRequest->refresh()->isPending());

        $this->actingAs($admin, 'platform')
            ->post(route('platform.trial-requests.reject', $trialRequest), ['review_note' => '請先完成帳號驗證'])
            ->assertRedirect(route('platform.trial-requests.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(TrialRequest::STATUS_REJECTED, $trialRequest->refresh()->status);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->actingAs($owner)->get(route('trials.index'))->assertSee('請先完成帳號驗證');

        $this->actingAs($owner)
            ->post(route('trials.store'), ['product_plan_id' => $this->plan->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, TrialRequest::query()->count());
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function ownerWithOrganization(): array
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);

        return [$owner, $organization];
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }
}
