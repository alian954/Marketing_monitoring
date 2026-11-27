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

    /**
     * Owners of organizations that opted into subscription access but are not entitled to a feature.
     *
     * Background jobs exclude these owners' data. Organizations still on the legacy model are never listed.
     *
     * @return list<int>
     */
    public function unentitledOwnerIds(string $productCode, string $featureKey, ?DateTimeInterface $asOf = null): array
    {
        return Organization::query()
            ->whereNotNull('entitlements_enforced_at')
            ->get()
            ->reject(fn (Organization $organization): bool => $this->resolve($organization, $productCode, $featureKey, $asOf)->isGranted)
            ->map(static fn (Organization $organization): int => (int) $organization->owner_user_id)
            ->values()
            ->all();
    }

    /**
     * Whether data owned by this workspace owner may be served for a feature on public or background paths.
     *
     * Owners without an organization, or organizations still on the legacy model, are always allowed.
     */
    public function ownerHasFeature(int $ownerUserId, string $productCode, string $featureKey): bool
    {
        $organization = Organization::query()->where('owner_user_id', $ownerUserId)->first();

        return $organization === null
            || ! $organization->enforcesEntitlements()
            || $this->resolve($organization, $productCode, $featureKey)->isGranted;
    }

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

        // Subscription timestamps are stored in the application timezone (UTC); compare the same instant, not Taiwan wall-clock time.
        $timestamp = $evaluationTime->copy()->setTimezone(config('app.timezone'))->toDateTimeString();
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
