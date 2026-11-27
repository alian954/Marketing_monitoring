<?php

namespace Tests\Feature;

use App\Models\BillingOrder;
use App\Models\BillingPayment;
use App\Models\Organization;
use App\Models\PlanEntitlement;
use App\Models\PlatformAuditAction;
use App\Models\PlatformAuditEvent;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Services\BillingLedger;
use App\Services\ProductEntitlementService;
use Carbon\CarbonImmutable;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProductCatalogSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-12-10 12:00:00', 'Asia/Taipei'));
    }

    public function test_access_is_provisioned_only_once_the_order_is_fully_paid(): void
    {
        $organization = Organization::factory()->create();
        $plan = $this->planWithFeature('website-monitoring', 'monitoring.targets', 100000);
        $order = $this->ledger()->createOrder($organization, [$this->line($plan, '2026-12-01', '2026-12-31')]);

        $this->ledger()->recordPayment($order, 40000, BillingPayment::METHOD_BANK_TRANSFER, now());
        $this->assertSame(0, Subscription::query()->count());
        $this->assertFalse($this->isGranted($organization));

        $this->ledger()->recordPayment($order, 60000, BillingPayment::METHOD_BANK_TRANSFER, now());

        $item = SubscriptionItem::query()->where('organization_id', $organization->id)->sole();
        $this->assertSame($plan->id, $item->product_plan_id);
        $this->assertSame('2026-11-30 16:00:00', $item->starts_at->utc()->toDateTimeString());
        $this->assertSame('2026-12-31 16:00:00', $item->ends_at->utc()->toDateTimeString());
        $this->assertSame('active', $item->subscription->status);
        $this->assertTrue($this->isGranted($organization));
        $this->assertDatabaseHas('platform_audit_events', [
            'action' => PlatformAuditAction::SubscriptionItemCreated->value,
            'subject_id' => $item->id,
        ]);
        $this->assertSame(
            $order->order_number,
            PlatformAuditEvent::query()->where('action', PlatformAuditAction::SubscriptionItemCreated)->sole()->metadata['source_order_number'],
        );
    }

    public function test_renewal_extends_the_same_product_and_a_plan_change_replaces_the_plan(): void
    {
        $organization = Organization::factory()->create();
        $basicPlan = $this->planWithFeature('website-monitoring', 'monitoring.targets', 100000);
        $proPlan = $this->planWithFeature('website-monitoring', 'monitoring.targets', 200000);

        $this->payInFull($organization, $basicPlan, '2026-12-01', '2026-12-31');
        $this->payInFull($organization, $proPlan, '2027-01-01', '2027-01-31');

        $item = SubscriptionItem::query()->where('organization_id', $organization->id)->sole();
        $this->assertSame($proPlan->id, $item->product_plan_id);
        $this->assertSame('2026-11-30 16:00:00', $item->starts_at->utc()->toDateTimeString());
        $this->assertSame('2027-01-31 16:00:00', $item->ends_at->utc()->toDateTimeString());
        $this->assertSame(1, Subscription::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(
            '2027-01-31 16:00:00',
            Subscription::query()->where('organization_id', $organization->id)->sole()->current_period_ends_at->utc()->toDateTimeString(),
        );
    }

    public function test_a_lapsed_product_restarts_from_the_new_service_start(): void
    {
        $organization = Organization::factory()->create();
        $plan = $this->planWithFeature('website-monitoring', 'monitoring.targets', 100000);

        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'Asia/Taipei'));
        $this->payInFull($organization, $plan, '2026-10-01', '2026-10-31');
        $this->travelTo(CarbonImmutable::parse('2026-12-10 12:00:00', 'Asia/Taipei'));
        $this->assertFalse($this->isGranted($organization));

        $this->payInFull($organization, $plan, '2026-12-01', '2026-12-31');

        $item = SubscriptionItem::query()->where('organization_id', $organization->id)->sole();
        $this->assertSame('2026-11-30 16:00:00', $item->starts_at->utc()->toDateTimeString());
        $this->assertTrue($this->isGranted($organization));
    }

    public function test_enforced_organization_gains_product_access_after_paying(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create([
            'owner_user_id' => $owner->id,
            'entitlements_enforced_at' => now(),
        ]);
        $plan = $this->planWithFeature('website-monitoring', 'monitoring.targets', 100000);

        $this->actingAs($owner)->get(route('monitors.index'))->assertForbidden();

        $this->payInFull($organization, $plan, '2026-12-01', '2026-12-31');

        $this->get(route('monitors.index'))->assertOk();
        $this->get(route('incidents.index'))->assertForbidden();
    }

    private function payInFull(Organization $organization, ProductPlan $plan, string $startsOn, string $endsOn): BillingOrder
    {
        $order = $this->ledger()->createOrder($organization, [$this->line($plan, $startsOn, $endsOn)]);
        $this->ledger()->recordPayment($order, $order->total_minor, BillingPayment::METHOD_BANK_TRANSFER, now());

        return $order->refresh();
    }

    private function isGranted(Organization $organization): bool
    {
        return app(ProductEntitlementService::class)
            ->resolve($organization->refresh(), 'website-monitoring', 'monitoring.targets')
            ->isGranted;
    }

    private function planWithFeature(string $productCode, string $featureKey, int $amountMinor): ProductPlan
    {
        $product = Product::query()->where('product_code', $productCode)->firstOrFail();
        $plan = ProductPlan::factory()->for($product)->create([
            'status' => 'active',
            'amount_minor' => $amountMinor,
            'currency_code' => 'TWD',
        ]);
        PlanEntitlement::query()->create([
            'product_id' => $product->id,
            'product_plan_id' => $plan->id,
            'feature_key' => $featureKey,
            'is_enabled' => true,
            'limit_value' => null,
        ]);

        return $plan;
    }

    /**
     * @return array{product_plan_id: int, quantity: int, service_starts_on: string, service_ends_on: string}
     */
    private function line(ProductPlan $plan, string $startsOn, string $endsOn): array
    {
        return [
            'product_plan_id' => $plan->id,
            'quantity' => 1,
            'service_starts_on' => $startsOn,
            'service_ends_on' => $endsOn,
        ];
    }

    private function ledger(): BillingLedger
    {
        return app(BillingLedger::class);
    }
}
