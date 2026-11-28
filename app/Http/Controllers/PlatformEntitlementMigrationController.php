<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\ProductPlan;
use App\Services\LegacyEntitlementMigration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Platform page for moving legacy organizations onto subscription-based access in batches.
 */
class PlatformEntitlementMigrationController extends Controller
{
    private const MAX_BATCH_SIZE = 200;

    public function __construct(private LegacyEntitlementMigration $migration) {}

    public function index(): View
    {
        $organizations = $this->migration->pendingOrganizations()->load('owner:id,name,email');
        $rows = $organizations->map(fn (Organization $organization): array => [
            'organization' => $organization,
            'has_active_legacy_access' => $this->migration->hasActiveLegacyAccess($organization),
            'missing_features' => $this->migration->missingFeatureLabels($organization),
        ]);
        $plans = $this->migration->mappablePlans();

        return view('platform.entitlement-migration.index', [
            'rows' => $rows,
            'readyCount' => $rows->filter(static fn (array $row): bool => $row['has_active_legacy_access'] && $row['missing_features'] === [])->count(),
            'plansByProduct' => $plans->groupBy('product_id'),
        ]);
    }

    public function mapSubscriptions(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_ids' => ['required', 'array', 'max:'.self::MAX_BATCH_SIZE],
            'organization_ids.*' => ['integer', 'distinct'],
            'plan_ids' => ['required', 'array'],
            'plan_ids.*' => ['nullable', 'integer'],
        ], [
            'organization_ids.required' => '請至少勾選一個組織。',
            'organization_ids.max' => '一次最多處理 '.self::MAX_BATCH_SIZE.' 個組織。',
            'plan_ids.required' => '請至少為一項產品選擇對照方案。',
        ]);
        $plans = $this->selectedPlans($validated['plan_ids']);
        $mappedOrganizationCount = 0;
        $createdItemCount = 0;

        foreach ($this->selectedPendingOrganizations($validated['organization_ids']) as $organization) {
            $createdItems = $this->migration->mapToPlans($organization, $plans);

            if ($createdItems > 0) {
                $mappedOrganizationCount++;
                $createdItemCount += $createdItems;
            }
        }

        return redirect()
            ->route('platform.entitlement-migration.index')
            ->with('status', "已為 {$mappedOrganizationCount} 個組織建立 {$createdItemCount} 項對照訂閱；已有該產品訂閱、已停用或已到期的組織會略過。");
    }

    public function enforce(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_ids' => ['required', 'array', 'max:'.self::MAX_BATCH_SIZE],
            'organization_ids.*' => ['integer', 'distinct'],
        ], [
            'organization_ids.required' => '請至少勾選一個組織。',
            'organization_ids.max' => '一次最多處理 '.self::MAX_BATCH_SIZE.' 個組織。',
        ]);
        $enforcedCount = 0;
        $skippedNames = [];

        foreach ($this->selectedPendingOrganizations($validated['organization_ids']) as $organization) {
            if ($this->migration->enforceIfReady($organization)) {
                $enforcedCount++;
            } else {
                $skippedNames[] = $organization->name;
            }
        }

        $message = "已為 {$enforcedCount} 個組織啟用訂閱授權。";

        if ($skippedNames !== []) {
            $message .= '略過啟用後會失去功能的組織：'.implode('、', array_slice($skippedNames, 0, 10)).(count($skippedNames) > 10 ? ' 等 '.count($skippedNames).' 個' : '').'。';
        }

        return redirect()->route('platform.entitlement-migration.index')->with('status', $message);
    }

    /**
     * @param  array<int|string, mixed>  $planIdsByProduct
     * @return Collection<int, ProductPlan>
     */
    private function selectedPlans(array $planIdsByProduct): Collection
    {
        $mappablePlans = $this->migration->mappablePlans()->keyBy('id');
        $plans = collect();

        foreach ($planIdsByProduct as $productId => $planId) {
            if ($planId === null || $planId === '') {
                continue;
            }

            $plan = $mappablePlans->get((int) $planId);

            if (! $plan instanceof ProductPlan || (int) $plan->product_id !== (int) $productId) {
                throw ValidationException::withMessages(['plan_ids' => '所選方案已停售或不屬於該產品，請重新選擇。']);
            }

            $plans->push($plan);
        }

        if ($plans->isEmpty()) {
            throw ValidationException::withMessages(['plan_ids' => '請至少為一項產品選擇對照方案。']);
        }

        return $plans;
    }

    /**
     * @param  list<int|string>  $organizationIds
     * @return Collection<int, Organization>
     */
    private function selectedPendingOrganizations(array $organizationIds): Collection
    {
        return Organization::query()
            ->whereNull('entitlements_enforced_at')
            ->whereKey(array_map('intval', $organizationIds))
            ->orderBy('id')
            ->get();
    }
}
