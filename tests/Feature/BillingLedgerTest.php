<?php

namespace Tests\Feature;

use App\Models\BillingOrder;
use App\Models\BillingPayment;
use App\Models\Organization;
use App\Models\PlatformAuditAction;
use App\Models\PlatformAuditEvent;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Services\BillingLedger;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class BillingLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProductCatalogSeeder::class);
    }

    public function test_order_prices_come_from_sellable_plans_across_products(): void
    {
        $organization = Organization::factory()->create();
        $monitoringPlan = $this->sellablePlan('website-monitoring', 150000);
        $marketingPlan = $this->sellablePlan('marketing-insights', 99000);

        $order = $this->ledger()->createOrder($organization, [
            $this->line($monitoringPlan, 2),
            $this->line($marketingPlan, 1),
        ], '首年合約');

        $this->assertSame(BillingOrder::STATUS_ISSUED, $order->status);
        $this->assertSame('TWD', $order->currency_code);
        $this->assertSame(399000, $order->total_minor);
        $this->assertMatchesRegularExpression('/\AORD-\d{8}-[A-Z0-9]{8}\z/', $order->order_number);
        $this->assertSame([300000, 99000], $order->items()->orderBy('id')->pluck('amount_minor')->all());
        $this->assertSame(399000, $order->balanceMinor());
        $this->assertDatabaseHas('platform_audit_events', [
            'action' => PlatformAuditAction::BillingOrderCreated->value,
            'subject_id' => $order->id,
        ]);
    }

    public function test_orders_reject_unsellable_plans_and_mixed_currencies(): void
    {
        $organization = Organization::factory()->create();
        $retiredPlan = $this->sellablePlan('website-monitoring', 1000, ['status' => 'retired']);
        $unpricedPlan = $this->sellablePlan('website-monitoring', null);
        $usdPlan = $this->sellablePlan('marketing-insights', 5000, ['currency_code' => 'USD']);
        $twdPlan = $this->sellablePlan('website-monitoring', 5000);

        foreach ([[$retiredPlan], [$unpricedPlan], [$twdPlan, $usdPlan]] as $plans) {
            try {
                $this->ledger()->createOrder($organization, array_map(fn (ProductPlan $plan): array => $this->line($plan), $plans));
                $this->fail('Unsellable or mixed-currency order should be rejected.');
            } catch (ValidationException) {
            }
        }

        Product::query()->where('product_code', 'website-monitoring')->update(['lifecycle' => 'maintenance']);

        $this->expectException(ValidationException::class);
        $this->ledger()->createOrder($organization, [$this->line($twdPlan)]);
    }

    public function test_partial_payments_settle_the_order_and_overpayment_is_rejected(): void
    {
        $order = $this->ledger()->createOrder(Organization::factory()->create(), [
            $this->line($this->sellablePlan('website-monitoring', 100000)),
        ]);

        $this->ledger()->recordPayment($order, 40000, BillingPayment::METHOD_BANK_TRANSFER, now()->subDay(), '匯款末五碼 12345');
        $this->assertSame(BillingOrder::STATUS_ISSUED, $order->fresh()->status);
        $this->assertSame(60000, $order->fresh()->balanceMinor());

        try {
            $this->ledger()->recordPayment($order, 60001, BillingPayment::METHOD_CASH, now());
            $this->fail('Overpayment should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $receivedAt = now()->startOfMinute();
        $this->ledger()->recordPayment($order, 60000, BillingPayment::METHOD_CASH, $receivedAt);

        $order->refresh();
        $this->assertSame(BillingOrder::STATUS_PAID, $order->status);
        $this->assertTrue($receivedAt->equalTo($order->paid_at));
        $this->assertSame(0, $order->balanceMinor());
        $this->assertSame(2, PlatformAuditEvent::query()->where('action', PlatformAuditAction::BillingPaymentRecorded)->count());

        $this->expectException(ValidationException::class);
        $this->ledger()->recordPayment($order, 1, BillingPayment::METHOD_CASH, now());
    }

    public function test_only_unpaid_orders_can_be_canceled(): void
    {
        $plan = $this->sellablePlan('website-monitoring', 100000);
        $organization = Organization::factory()->create();
        $unpaidOrder = $this->ledger()->createOrder($organization, [$this->line($plan)]);
        $partlyPaidOrder = $this->ledger()->createOrder($organization, [$this->line($plan)]);
        $this->ledger()->recordPayment($partlyPaidOrder, 1000, BillingPayment::METHOD_OTHER, now());

        $canceled = $this->ledger()->cancelOrder($unpaidOrder, '客戶改簽年約');
        $this->assertSame(BillingOrder::STATUS_CANCELED, $canceled->status);
        $this->assertSame('客戶改簽年約', $canceled->cancellation_reason);

        $this->expectException(ValidationException::class);
        $this->ledger()->cancelOrder($partlyPaidOrder, '不應取消');
    }

    public function test_refunds_track_net_paid_amounts_and_mark_full_refunds(): void
    {
        $plan = $this->sellablePlan('website-monitoring', 100000);
        $organization = Organization::factory()->create();
        $unpaidOrder = $this->ledger()->createOrder($organization, [$this->line($plan)]);
        $order = $this->ledger()->createOrder($organization, [$this->line($plan)]);
        $this->ledger()->recordPayment($order, 100000, BillingPayment::METHOD_BANK_TRANSFER, now());

        try {
            $this->ledger()->recordRefund($unpaidOrder, 100, BillingPayment::METHOD_CASH, now(), '未收款');
            $this->fail('Unpaid orders cannot be refunded.');
        } catch (ValidationException) {
        }

        $this->ledger()->recordRefund($order, 30000, BillingPayment::METHOD_BANK_TRANSFER, now(), '服務折讓');
        $order->refresh();
        $this->assertSame(BillingOrder::STATUS_PAID, $order->status);
        $this->assertSame(70000, $order->netPaidMinor());

        try {
            $this->ledger()->recordRefund($order, 70001, BillingPayment::METHOD_BANK_TRANSFER, now(), '超額');
            $this->fail('Refunds cannot exceed the net paid amount.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('refund_amount', $exception->errors());
        }

        $refund = $this->ledger()->recordRefund($order, 70000, BillingPayment::METHOD_BANK_TRANSFER, now(), '客戶解約');
        $this->assertSame(BillingOrder::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertSame(0, $order->fresh()->netPaidMinor());
        $this->assertSame(2, PlatformAuditEvent::query()->where('action', PlatformAuditAction::BillingRefundRecorded)->count());

        $this->expectException(LogicException::class);
        $refund->delete();
    }

    public function test_payments_are_append_only(): void
    {
        $payment = BillingPayment::factory()->create();

        try {
            $payment->update(['amount_minor' => 1]);
            $this->fail('Payments must not be updated.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $payment->delete();
    }

    public function test_payment_cannot_reference_an_order_from_another_organization(): void
    {
        $order = BillingOrder::factory()->create();
        $otherOrganization = Organization::factory()->create();

        $this->expectException(QueryException::class);

        BillingPayment::factory()->create([
            'billing_order_id' => $order->id,
            'organization_id' => $otherOrganization->id,
        ]);
    }

    public function test_organization_with_billing_records_cannot_be_deleted(): void
    {
        $order = BillingOrder::factory()->create();

        $this->expectException(QueryException::class);

        $order->organization->delete();
    }

    private function ledger(): BillingLedger
    {
        return app(BillingLedger::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function sellablePlan(string $productCode, ?int $amountMinor, array $attributes = []): ProductPlan
    {
        return ProductPlan::factory()->create([
            'product_id' => Product::query()->where('product_code', $productCode)->value('id'),
            'status' => 'active',
            'amount_minor' => $amountMinor,
            'currency_code' => 'TWD',
            ...$attributes,
        ]);
    }

    /**
     * @return array{product_plan_id: int, quantity: int, service_starts_on: string, service_ends_on: string}
     */
    private function line(ProductPlan $plan, int $quantity = 1): array
    {
        return [
            'product_plan_id' => $plan->id,
            'quantity' => $quantity,
            'service_starts_on' => '2026-12-01',
            'service_ends_on' => '2027-11-30',
        ];
    }
}
