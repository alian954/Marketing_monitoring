<?php

namespace App\Services;

use App\Models\BillingOrder;
use App\Models\BillingOrderItem;
use App\Models\BillingPayment;
use App\Models\BillingRefund;
use App\Models\Organization;
use App\Models\PlatformAuditAction;
use App\Models\ProductPlan;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Central billing ledger for orders and manually recorded payments.
 *
 * Prices always come from the sellable product plan, never from the request, so every product is billed through
 * the same rules. Payments are append-only and may not exceed the outstanding balance; an order becomes paid once
 * fully settled, at which point the paid products are provisioned onto the organization's subscription.
 * Payment gateways can later record payments through the same entry point.
 */
class BillingLedger
{
    public function __construct(
        private readonly PlatformAuditLogger $auditLogger,
        private readonly SubscriptionProvisioner $subscriptionProvisioner,
    ) {}

    /**
     * @param  list<array{product_plan_id: int, quantity: int, service_starts_on: string, service_ends_on: string}>  $lines
     *
     * @throws ValidationException
     */
    public function createOrder(Organization $organization, array $lines, ?string $notes = null): BillingOrder
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => '訂單至少需要一個產品項目。']);
        }

        return DB::transaction(function () use ($organization, $lines, $notes): BillingOrder {
            Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

            $plans = ProductPlan::query()
                ->with('product:id,name,lifecycle')
                ->whereIn('id', array_column($lines, 'product_plan_id'))
                ->get()
                ->keyBy('id');
            $currencyCode = null;
            $totalMinor = 0;
            $itemAttributes = [];

            foreach ($lines as $index => $line) {
                $plan = $plans->get($line['product_plan_id']);

                if (! $plan instanceof ProductPlan
                    || $plan->status !== 'active'
                    || $plan->amount_minor === null
                    || $plan->currency_code === null
                    || $plan->product?->lifecycle !== 'active') {
                    throw ValidationException::withMessages([
                        "lines.{$index}.product_plan_id" => '請選擇目前可銷售的產品方案。',
                    ]);
                }

                $currencyCode ??= $plan->currency_code;

                if ($currencyCode !== $plan->currency_code) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.product_plan_id" => '同一張訂單的方案幣別必須一致。',
                    ]);
                }

                $amountMinor = (int) $plan->amount_minor * $line['quantity'];
                $totalMinor += $amountMinor;
                $itemAttributes[] = [
                    'organization_id' => $organization->getKey(),
                    'product_id' => $plan->product_id,
                    'product_plan_id' => $plan->getKey(),
                    'description' => mb_substr($plan->product->name.' · '.$plan->name, 0, 160),
                    'quantity' => $line['quantity'],
                    'unit_amount_minor' => (int) $plan->amount_minor,
                    'amount_minor' => $amountMinor,
                    'service_starts_on' => $line['service_starts_on'],
                    'service_ends_on' => $line['service_ends_on'],
                ];
            }

            $order = BillingOrder::query()->create([
                'organization_id' => $organization->getKey(),
                'order_number' => $this->nextOrderNumber(),
                'status' => BillingOrder::STATUS_ISSUED,
                'currency_code' => $currencyCode,
                'total_minor' => $totalMinor,
                'issued_at' => now(),
                'notes' => $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 500) : null,
                'created_by_user_id' => request()->user()?->getKey(),
            ]);

            foreach ($itemAttributes as $attributes) {
                BillingOrderItem::query()->create(['billing_order_id' => $order->getKey(), ...$attributes]);
            }

            $this->auditLogger->record(PlatformAuditAction::BillingOrderCreated, $order, [
                'order_number' => $order->order_number,
                'total_minor' => $totalMinor,
                'currency_code' => $currencyCode,
                'item_count' => count($itemAttributes),
            ], (int) $organization->owner_user_id);

            return $order;
        });
    }

    /**
     * @throws ValidationException
     */
    public function recordPayment(
        BillingOrder $order,
        int $amountMinor,
        string $method,
        CarbonInterface $receivedAt,
        ?string $reference = null,
    ): BillingPayment {
        if ($amountMinor <= 0) {
            throw ValidationException::withMessages(['amount' => '收款金額必須大於 0。']);
        }

        if (! in_array($method, BillingPayment::METHODS, true)) {
            throw ValidationException::withMessages(['method' => '請選擇有效的收款方式。']);
        }

        return DB::transaction(function () use ($order, $amountMinor, $method, $receivedAt, $reference): BillingPayment {
            $lockedOrder = BillingOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status !== BillingOrder::STATUS_ISSUED) {
                throw ValidationException::withMessages(['amount' => '只有待收款的訂單可以登錄收款。']);
            }

            if ($amountMinor > $lockedOrder->balanceMinor()) {
                throw ValidationException::withMessages(['amount' => '收款金額不可超過未收餘額。']);
            }

            $payment = BillingPayment::query()->create([
                'billing_order_id' => $lockedOrder->getKey(),
                'organization_id' => $lockedOrder->organization_id,
                'method' => $method,
                'amount_minor' => $amountMinor,
                'currency_code' => $lockedOrder->currency_code,
                'received_at' => $receivedAt,
                'reference' => $reference !== null && trim($reference) !== '' ? mb_substr(trim($reference), 0, 120) : null,
                'recorded_by_user_id' => request()->user()?->getKey(),
            ]);

            if ($lockedOrder->balanceMinor() === 0) {
                $lockedOrder->forceFill([
                    'status' => BillingOrder::STATUS_PAID,
                    'paid_at' => $receivedAt,
                ])->save();
                $this->subscriptionProvisioner->provisionPaidOrder($lockedOrder);
            }

            $this->auditLogger->record(PlatformAuditAction::BillingPaymentRecorded, $payment, [
                'order_number' => $lockedOrder->order_number,
                'amount_minor' => $amountMinor,
                'currency_code' => $lockedOrder->currency_code,
                'method' => $method,
                'order_status' => $lockedOrder->status,
            ], (int) $lockedOrder->organization->owner_user_id);

            return $payment;
        });
    }

    /**
     * Record a refund against a paid order. A full refund marks the order refunded; a partial refund is an allowance
     * and the order stays paid. Subscription access is left for the operator to adjust.
     *
     * @throws ValidationException
     */
    public function recordRefund(
        BillingOrder $order,
        int $amountMinor,
        string $method,
        CarbonInterface $refundedAt,
        string $reason,
        ?string $reference = null,
    ): BillingRefund {
        if ($amountMinor <= 0) {
            throw ValidationException::withMessages(['refund_amount' => '退款金額必須大於 0。']);
        }

        if (! in_array($method, BillingPayment::METHODS, true)) {
            throw ValidationException::withMessages(['refund_method' => '請選擇有效的退款方式。']);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['refund_reason' => '請輸入退款原因。']);
        }

        return DB::transaction(function () use ($order, $amountMinor, $method, $refundedAt, $reason, $reference): BillingRefund {
            $lockedOrder = BillingOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status !== BillingOrder::STATUS_PAID) {
                throw ValidationException::withMessages(['refund_amount' => '只有已收款的訂單可以退款。']);
            }

            if ($amountMinor > $lockedOrder->netPaidMinor()) {
                throw ValidationException::withMessages(['refund_amount' => '退款金額不可超過已收淨額。']);
            }

            $refund = BillingRefund::query()->create([
                'billing_order_id' => $lockedOrder->getKey(),
                'organization_id' => $lockedOrder->organization_id,
                'method' => $method,
                'amount_minor' => $amountMinor,
                'currency_code' => $lockedOrder->currency_code,
                'refunded_at' => $refundedAt,
                'reason' => mb_substr(trim($reason), 0, 255),
                'reference' => $reference !== null && trim($reference) !== '' ? mb_substr(trim($reference), 0, 120) : null,
                'recorded_by_user_id' => request()->user()?->getKey(),
            ]);

            if ($lockedOrder->netPaidMinor() === 0) {
                $lockedOrder->forceFill(['status' => BillingOrder::STATUS_REFUNDED])->save();
            }

            $this->auditLogger->record(PlatformAuditAction::BillingRefundRecorded, $refund, [
                'order_number' => $lockedOrder->order_number,
                'amount_minor' => $amountMinor,
                'currency_code' => $lockedOrder->currency_code,
                'method' => $method,
                'reason' => $refund->reason,
                'order_status' => $lockedOrder->status,
            ], (int) $lockedOrder->organization->owner_user_id);

            return $refund;
        });
    }

    /**
     * @throws ValidationException
     */
    public function cancelOrder(BillingOrder $order, string $reason): BillingOrder
    {
        return DB::transaction(function () use ($order, $reason): BillingOrder {
            $lockedOrder = BillingOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status !== BillingOrder::STATUS_ISSUED || $lockedOrder->payments()->exists()) {
                throw ValidationException::withMessages(['reason' => '只有尚未收款的待收款訂單可以取消。']);
            }

            $lockedOrder->forceFill([
                'status' => BillingOrder::STATUS_CANCELED,
                'canceled_at' => now(),
                'cancellation_reason' => mb_substr(trim($reason), 0, 255),
            ])->save();

            $this->auditLogger->record(PlatformAuditAction::BillingOrderCanceled, $lockedOrder, [
                'order_number' => $lockedOrder->order_number,
                'reason' => $lockedOrder->cancellation_reason,
            ], (int) $lockedOrder->organization->owner_user_id);

            return $lockedOrder;
        });
    }

    private function nextOrderNumber(): string
    {
        do {
            $orderNumber = 'ORD-'.now('Asia/Taipei')->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (BillingOrder::query()->where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }
}
