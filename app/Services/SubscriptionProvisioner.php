<?php

namespace App\Services;

use App\Models\BillingOrder;
use App\Models\BillingOrderItem;
use App\Models\PlatformAuditAction;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use Carbon\CarbonImmutable;

/**
 * Turns a fully paid billing order into subscription access.
 *
 * Each order line activates or renews its product on the organization's current contract: renewals extend the end
 * date, plan changes replace the plan, and a lapsed product restarts from the new service start. Service dates are
 * Taiwan calendar days; access ends at 00:00 Taiwan time on the day after the last service day. Callers run this
 * inside the payment transaction so access and the payment are recorded together.
 */
class SubscriptionProvisioner
{
    public function __construct(private readonly PlatformAuditLogger $auditLogger) {}

    public function provisionPaidOrder(BillingOrder $order): Subscription
    {
        $order->loadMissing(['items', 'organization']);
        $windows = $order->items->map(fn (BillingOrderItem $item): array => $this->accessWindow($item));
        $orderStartsAt = $windows->min('starts_at');
        $orderEndsAt = $windows->max('ends_at');

        $subscription = Subscription::query()
            ->where('organization_id', $order->organization_id)
            ->whereIn('status', ['active', 'trialing'])
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if ($subscription instanceof Subscription) {
            $subscription->update([
                'status' => 'active',
                'current_period_ends_at' => $subscription->current_period_ends_at === null
                    ? null
                    : max($subscription->current_period_ends_at, $orderEndsAt),
            ]);
        } else {
            $subscription = Subscription::query()->create([
                'organization_id' => $order->organization_id,
                'status' => 'active',
                'starts_at' => $orderStartsAt,
                'current_period_starts_at' => $orderStartsAt,
                'current_period_ends_at' => $orderEndsAt,
                'cancel_at_period_end' => false,
            ]);
        }

        foreach ($order->items as $index => $orderItem) {
            $this->provisionItem($order, $subscription, $orderItem, $windows[$index]);
        }

        return $subscription;
    }

    /**
     * @param  array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}  $window
     */
    private function provisionItem(BillingOrder $order, Subscription $subscription, BillingOrderItem $orderItem, array $window): void
    {
        $existingItem = SubscriptionItem::query()
            ->where('organization_id', $order->organization_id)
            ->where('product_id', $orderItem->product_id)
            ->lockForUpdate()
            ->first();

        if (! $existingItem instanceof SubscriptionItem) {
            $item = SubscriptionItem::query()->create([
                'organization_id' => $order->organization_id,
                'subscription_id' => $subscription->getKey(),
                'product_id' => $orderItem->product_id,
                'product_plan_id' => $orderItem->product_plan_id,
                'status' => 'active',
                'quantity' => $orderItem->quantity,
                'starts_at' => $window['starts_at'],
                'ends_at' => $window['ends_at'],
            ]);
            $this->recordProvisioning(PlatformAuditAction::SubscriptionItemCreated, $order, $item);

            return;
        }

        $isContinuous = $existingItem->status === 'active'
            && ($existingItem->ends_at === null || $existingItem->ends_at->greaterThanOrEqualTo($window['starts_at']));

        $endsAt = match (true) {
            ! $isContinuous => $window['ends_at'],
            $existingItem->ends_at === null => null,
            default => max($existingItem->ends_at, $window['ends_at']),
        };

        $existingItem->update([
            'subscription_id' => $subscription->getKey(),
            'product_plan_id' => $orderItem->product_plan_id,
            'quantity' => $orderItem->quantity,
            'status' => 'active',
            'starts_at' => $isContinuous ? min($existingItem->starts_at, $window['starts_at']) : $window['starts_at'],
            'ends_at' => $endsAt,
        ]);
        $this->recordProvisioning(PlatformAuditAction::SubscriptionItemUpdated, $order, $existingItem);
    }

    /**
     * @return array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}
     */
    private function accessWindow(BillingOrderItem $item): array
    {
        $timezone = config('app.timezone');

        return [
            'starts_at' => CarbonImmutable::parse($item->service_starts_on->toDateString(), 'Asia/Taipei')->startOfDay()->setTimezone($timezone),
            'ends_at' => CarbonImmutable::parse($item->service_ends_on->toDateString(), 'Asia/Taipei')->addDay()->startOfDay()->setTimezone($timezone),
        ];
    }

    private function recordProvisioning(PlatformAuditAction $action, BillingOrder $order, SubscriptionItem $item): void
    {
        $this->auditLogger->record($action, $item, [
            'source_order_number' => $order->order_number,
            'product_id' => $item->product_id,
            'product_plan_id' => $item->product_plan_id,
            'quantity' => $item->quantity,
            'starts_at' => $item->starts_at?->format(DATE_ATOM),
            'ends_at' => $item->ends_at?->format(DATE_ATOM),
        ], (int) $order->organization->owner_user_id);
    }
}
