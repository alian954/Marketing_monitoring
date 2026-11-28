<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\PlatformAuditAction;
use App\Models\ProductPlan;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * Moves organizations from the legacy plan fields onto subscription-based access without taking features away.
 */
class LegacyEntitlementMigration
{
    public const AUDIT_SOURCE = 'legacy_migration';

    public function __construct(
        private ProductRegistry $productRegistry,
        private ProductEntitlementService $entitlements,
        private PlatformAuditLogger $auditLogger,
    ) {}

    /**
     * Product features the organization would lose if subscription access were enforced now.
     *
     * @return list<string>
     */
    public function missingFeatureLabels(Organization $organization): array
    {
        $missingFeatures = [];

        foreach ($this->productRegistry->all() as $productCode => $manifest) {
            foreach ($manifest['feature_keys'] as $featureKey) {
                if (! $this->entitlements->resolve($organization, $productCode, $featureKey)->isGranted) {
                    $missingFeatures[] = $manifest['feature_labels'][$featureKey];
                }
            }
        }

        return $missingFeatures;
    }

    /**
     * Organizations still on the legacy model, oldest first.
     *
     * @return Collection<int, Organization>
     */
    public function pendingOrganizations(?int $organizationId = null): Collection
    {
        return Organization::query()
            ->whereNull('entitlements_enforced_at')
            ->when($organizationId !== null, static fn (Builder $query): Builder => $query->whereKey($organizationId))
            ->orderBy('id')
            ->get();
    }

    /**
     * Plans a legacy organization may be mapped to: active, priced plans of active products.
     *
     * @return Collection<int, ProductPlan>
     */
    public function mappablePlans(): Collection
    {
        return ProductPlan::query()
            ->with('product:id,name,product_code')
            ->where('status', 'active')
            ->whereNotNull('amount_minor')
            ->whereNotNull('currency_code')
            ->whereHas('product', static fn (Builder $query): Builder => $query->where('lifecycle', 'active'))
            ->orderBy('product_id')
            ->orderBy('name')
            ->get();
    }

    /**
     * Give the organization the chosen plan for every product it does not subscribe to yet, ending when its legacy access ends.
     *
     * @param  SupportCollection<int, ProductPlan>  $plans  At most one plan per product.
     * @return int The number of product items created.
     */
    public function mapToPlans(Organization $organization, SupportCollection $plans): int
    {
        return DB::transaction(function () use ($organization, $plans): int {
            $lockedOrganization = Organization::query()->lockForUpdate()->findOrFail($organization->getKey());

            if ($lockedOrganization->enforcesEntitlements() || ! $this->hasActiveLegacyAccess($lockedOrganization)) {
                return 0;
            }

            $plansToAdd = $plans->reject(
                static fn (ProductPlan $plan): bool => $lockedOrganization->subscriptionItems()->where('product_id', $plan->product_id)->exists(),
            );

            if ($plansToAdd->isEmpty()) {
                return 0;
            }

            $ownerUserId = (int) $lockedOrganization->owner_user_id;
            $startsAt = CarbonImmutable::now();
            $endsAt = $this->legacyAccessEndsAt($lockedOrganization);
            $subscription = Subscription::query()
                ->where('organization_id', $lockedOrganization->getKey())
                ->whereIn('status', ['active', 'trialing'])
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $subscription instanceof Subscription) {
                $subscription = $lockedOrganization->subscriptions()->create([
                    'status' => 'active',
                    'starts_at' => $startsAt,
                    'current_period_starts_at' => $startsAt,
                    'current_period_ends_at' => $endsAt,
                    'cancel_at_period_end' => false,
                ]);
                $this->auditLogger->record(PlatformAuditAction::SubscriptionCreated, $subscription, [
                    'source' => self::AUDIT_SOURCE,
                    'status' => 'active',
                    'current_period_ends_at' => $endsAt?->format(DATE_ATOM),
                ], $ownerUserId);
            }

            foreach ($plansToAdd as $plan) {
                $item = SubscriptionItem::query()->create([
                    'organization_id' => $lockedOrganization->getKey(),
                    'subscription_id' => $subscription->getKey(),
                    'product_id' => $plan->product_id,
                    'product_plan_id' => $plan->getKey(),
                    'status' => 'active',
                    'quantity' => 1,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                ]);
                $this->auditLogger->record(PlatformAuditAction::SubscriptionItemCreated, $item, [
                    'source' => self::AUDIT_SOURCE,
                    'subscription_id' => $subscription->getKey(),
                    'product_id' => $plan->product_id,
                    'product_plan_id' => $plan->getKey(),
                    'ends_at' => $endsAt?->format(DATE_ATOM),
                ], $ownerUserId);
            }

            return $plansToAdd->count();
        });
    }

    /**
     * Switch the organization to subscription access only when it would keep every feature.
     */
    public function enforceIfReady(Organization $organization): bool
    {
        return DB::transaction(function () use ($organization): bool {
            $lockedOrganization = Organization::query()->lockForUpdate()->findOrFail($organization->getKey());

            if ($lockedOrganization->enforcesEntitlements() || $this->missingFeatureLabels($lockedOrganization) !== []) {
                return false;
            }

            $lockedOrganization->forceFill(['entitlements_enforced_at' => now()])->save();
            $this->auditLogger->record(PlatformAuditAction::OrganizationUpdated, $lockedOrganization, [
                'source' => self::AUDIT_SOURCE,
                'changes' => $this->auditLogger->changes(['entitlements_enforced' => false], ['entitlements_enforced' => true]),
            ], (int) $lockedOrganization->owner_user_id);

            return true;
        });
    }

    /**
     * Disabled or expired organizations have nothing to carry over.
     */
    public function hasActiveLegacyAccess(Organization $organization): bool
    {
        $endsOn = $organization->subscription_ends_on?->toDateString();

        return $organization->is_enabled
            && ($endsOn === null || CarbonImmutable::now('Asia/Taipei')->toDateString() <= $endsOn);
    }

    /**
     * The legacy end date is inclusive in Taiwan time; access ends at the next Taiwan midnight. Null means no end date.
     */
    private function legacyAccessEndsAt(Organization $organization): ?CarbonImmutable
    {
        $endsOn = $organization->subscription_ends_on?->toDateString();

        return $endsOn === null
            ? null
            : CarbonImmutable::parse($endsOn, 'Asia/Taipei')->addDay()->startOfDay()->setTimezone(config('app.timezone'));
    }
}
