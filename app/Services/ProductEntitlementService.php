<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\PlanEntitlement;
use App\Models\Product;
use App\Models\SubscriptionItem;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ProductEntitlementService
{
    public function __construct(private readonly ProductRegistry $productRegistry) {}

    public function resolve(
        Organization $organization,
        string $productCode,
        string $featureKey,
        ?DateTimeInterface $asOf = null,
    ): ProductFeatureGrant {
        $evaluationTime = Carbon::instance($asOf ?? now('Asia/Taipei'))->setTimezone('Asia/Taipei');
        $manifest = $this->productRegistry->find($productCode);

        if ($manifest === null
            || $manifest['lifecycle'] !== 'active'
            || ! $this->organizationIsAccessibleAt($organization, $evaluationTime)
            || ! $this->productRegistry->declaresFeature($productCode, $featureKey)) {
            return ProductFeatureGrant::denied();
        }

        $product = Product::query()
            ->where('product_code', $productCode)
            ->where('lifecycle', 'active')
            ->first();

        if (! $product instanceof Product) {
            return ProductFeatureGrant::denied();
        }

        $timestamp = $evaluationTime->toDateTimeString();
        $subscriptionItem = SubscriptionItem::query()
            ->where('organization_id', $organization->getKey())
            ->where('product_id', $product->getKey())
            ->where('status', 'active')
            ->where('starts_at', '<=', $timestamp)
            ->where(function (Builder $query) use ($timestamp): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', $timestamp);
            })
            ->whereHas('subscription', function (Builder $query) use ($organization, $timestamp): void {
                $query->where('organization_id', $organization->getKey())
                    ->whereIn('status', ['active', 'trialing'])
                    ->where('starts_at', '<=', $timestamp)
                    ->where('current_period_starts_at', '<=', $timestamp)
                    ->where(function (Builder $query) use ($timestamp): void {
                        $query->whereNull('current_period_ends_at')
                            ->orWhere('current_period_ends_at', '>', $timestamp);
                    })
                    ->where(function (Builder $query) use ($timestamp): void {
                        $query->where('status', '<>', 'trialing')
                            ->orWhere(function (Builder $query) use ($timestamp): void {
                                $query->whereNotNull('trial_ends_at')
                                    ->where('trial_ends_at', '>', $timestamp);
                            });
                    });
            })
            ->whereHas('plan.entitlements', function (Builder $query) use ($featureKey, $product): void {
                $query->where('product_id', $product->getKey())
                    ->where('feature_key', $featureKey)
                    ->where('is_enabled', true);
            })
            ->with('plan')
            ->first();

        if (! $subscriptionItem instanceof SubscriptionItem) {
            return ProductFeatureGrant::denied();
        }

        $entitlement = $subscriptionItem->plan
            ->entitlements()
            ->where('product_id', $product->getKey())
            ->where('feature_key', $featureKey)
            ->where('is_enabled', true)
            ->first();

        return $entitlement instanceof PlanEntitlement
            ? ProductFeatureGrant::granted($entitlement->limit_value)
            : ProductFeatureGrant::denied();
    }

    private function organizationIsAccessibleAt(Organization $organization, Carbon $evaluationTime): bool
    {
        $legacySubscriptionEndDate = $organization->subscription_ends_on?->toDateString();

        return $organization->is_enabled
            && ($legacySubscriptionEndDate === null || $evaluationTime->toDateString() <= $legacySubscriptionEndDate);
    }
}
