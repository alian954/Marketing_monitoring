<?php

namespace Tests\Feature;

use App\Models\BillingOrder;
use App\Models\BillingPayment;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\User;
use App\Services\MoneyAmount;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformBillingManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProductCatalogSeeder::class);
    }

    public function test_only_platform_admins_can_open_or_change_an_organization_billing_ledger(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $order = BillingOrder::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($owner)->get(route('platform.organizations.billing', $organization))->assertForbidden();
        $this->post(route('platform.organizations.billing.orders.store', $organization), [])->assertForbidden();
        $this->post(route('platform.organizations.billing.payments.store', [$organization, $order]), [])->assertForbidden();
        $this->post(route('platform.organizations.billing.orders.cancel', [$organization, $order]), [])->assertForbidden();

        $this->actingAs($this->platformAdmin())
            ->get(route('platform.organizations.billing', $organization))
            ->assertOk()
            ->assertSee($organization->name.' · 訂單與收款')
            ->assertSee($order->order_number);
    }

    public function test_platform_admin_issues_an_order_and_settles_it_with_manual_payments(): void
    {
        $organization = Organization::factory()->create();
        $plan = $this->sellablePlan(150050);
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->post(route('platform.organizations.billing.orders.store', $organization), [
                'product_plan_id' => $plan->id,
                'quantity' => 2,
                'service_starts_on' => '2026-12-01',
                'service_ends_on' => '2027-11-30',
                'notes' => '年約',
                'amount_minor' => 1,
            ])
            ->assertRedirect(route('platform.organizations.billing', $organization))
            ->assertSessionHasNoErrors();

        $order = BillingOrder::query()->where('organization_id', $organization->id)->sole();
        $this->assertSame(300100, $order->total_minor);
        $this->assertSame($admin->id, $order->created_by_user_id);

        $this->post(route('platform.organizations.billing.payments.store', [$organization, $order]), [
            'amount' => '1000.5',
            'method' => BillingPayment::METHOD_BANK_TRANSFER,
            'received_at' => now('Asia/Taipei')->subHour()->format('Y-m-d\TH:i'),
            'reference' => '末五碼 54321',
        ])->assertSessionHasNoErrors();
        $this->post(route('platform.organizations.billing.payments.store', [$organization, $order]), [
            'amount' => '2000.50',
            'method' => BillingPayment::METHOD_CASH,
            'received_at' => now('Asia/Taipei')->format('Y-m-d\TH:i'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(BillingOrder::STATUS_PAID, $order->fresh()->status);
        $this->assertSame([100050, 200050], $order->payments()->orderBy('id')->pluck('amount_minor')->all());

        $this->get(route('platform.organizations.billing', $organization))
            ->assertOk()
            ->assertSee('已收款')
            ->assertSee('TWD 3,001.00')
            ->assertSee('末五碼 54321')
            ->assertDontSee('登錄收款</summary>', false);

        $this->get(route('platform.audit-events.index'))
            ->assertOk()
            ->assertSee('開立訂單')
            ->assertSee('登錄收款')
            ->assertSee('收款方式：銀行轉帳');
    }

    public function test_billing_routes_cannot_cross_organizations(): void
    {
        $organization = Organization::factory()->create();
        $otherOrder = BillingOrder::factory()->create();

        $this->actingAs($this->platformAdmin())
            ->post(route('platform.organizations.billing.payments.store', [$organization, $otherOrder]), [
                'amount' => '1',
                'method' => BillingPayment::METHOD_CASH,
                'received_at' => now('Asia/Taipei')->format('Y-m-d\TH:i'),
            ])
            ->assertNotFound();
        $this->post(route('platform.organizations.billing.orders.cancel', [$organization, $otherOrder]), ['reason' => '錯誤'])
            ->assertNotFound();

        $this->assertSame(0, BillingPayment::query()->count());
        $this->assertSame(BillingOrder::STATUS_ISSUED, $otherOrder->fresh()->status);
    }

    public function test_payment_input_is_validated_and_unpaid_orders_can_be_canceled(): void
    {
        $organization = Organization::factory()->create();
        $order = BillingOrder::factory()->create(['organization_id' => $organization->id, 'total_minor' => 5000]);
        $this->actingAs($this->platformAdmin());

        $this->post(route('platform.organizations.billing.payments.store', [$organization, $order]), [
            'amount' => '12.345',
            'method' => 'gateway',
            'received_at' => now('Asia/Taipei')->addDay()->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors(['amount', 'method', 'received_at']);

        $this->post(route('platform.organizations.billing.payments.store', [$organization, $order]), [
            'amount' => '50.01',
            'method' => BillingPayment::METHOD_CASH,
            'received_at' => now('Asia/Taipei')->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('amount');

        $this->post(route('platform.organizations.billing.orders.cancel', [$organization, $order]), ['reason' => '客戶取消'])
            ->assertRedirect(route('platform.organizations.billing', $organization));
        $this->assertSame(BillingOrder::STATUS_CANCELED, $order->fresh()->status);
    }

    public function test_platform_admin_records_a_refund_on_a_paid_order(): void
    {
        $organization = Organization::factory()->create();
        $order = BillingOrder::factory()->create([
            'organization_id' => $organization->id,
            'status' => BillingOrder::STATUS_PAID,
            'total_minor' => 5000,
        ]);
        BillingPayment::factory()->create(['billing_order_id' => $order->id, 'amount_minor' => 5000]);
        $otherOrder = BillingOrder::factory()->create(['status' => BillingOrder::STATUS_PAID]);
        $payload = [
            'refund_amount' => '50',
            'refund_method' => BillingPayment::METHOD_BANK_TRANSFER,
            'refunded_at' => now('Asia/Taipei')->format('Y-m-d\TH:i'),
            'refund_reason' => '客戶解約',
        ];

        $this->actingAs(User::factory()->create())
            ->post(route('platform.organizations.billing.refunds.store', [$organization, $order]), $payload)
            ->assertForbidden();

        $this->actingAs($this->platformAdmin())
            ->post(route('platform.organizations.billing.refunds.store', [$organization, $otherOrder]), $payload)
            ->assertNotFound();
        $this->post(route('platform.organizations.billing.refunds.store', [$organization, $order]), $payload)
            ->assertRedirect(route('platform.organizations.billing', $organization))
            ->assertSessionHasNoErrors();

        $this->assertSame(BillingOrder::STATUS_REFUNDED, $order->fresh()->status);
        $this->get(route('platform.organizations.billing', $organization))
            ->assertOk()
            ->assertSee('退款紀錄')
            ->assertSee('原因：客戶解約')
            ->assertSee('已退款');
    }

    public function test_money_amount_converts_decimal_input_to_minor_units(): void
    {
        $this->assertSame(150050, MoneyAmount::toMinorUnits('1500.5'));
        $this->assertSame(100, MoneyAmount::toMinorUnits('1'));
        $this->assertSame('TWD 1,500.50', MoneyAmount::format(150050, 'TWD'));
        $this->assertSame('1500.50', MoneyAmount::toDecimal(150050));

        $this->expectException(\InvalidArgumentException::class);
        MoneyAmount::toMinorUnits('-1');
    }

    private function sellablePlan(int $amountMinor): ProductPlan
    {
        return ProductPlan::factory()->create([
            'product_id' => Product::query()->where('product_code', 'website-monitoring')->value('id'),
            'status' => 'active',
            'amount_minor' => $amountMinor,
            'currency_code' => 'TWD',
        ]);
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }
}
