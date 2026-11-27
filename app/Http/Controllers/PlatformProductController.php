<?php

namespace App\Http\Controllers;

use App\Models\PlatformAuditAction;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Services\PlatformAuditLogger;
use App\Services\ProductRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PlatformProductController extends Controller
{
    public function __construct(private PlatformAuditLogger $auditLogger) {}

    public function index(ProductRegistry $productRegistry): View
    {
        $products = Product::query()
            ->with(['features' => fn ($query) => $query->orderBy('display_name'), 'plans' => fn ($query) => $query
                ->with('entitlements')
                ->orderBy('product_id')
                ->orderBy('plan_code')
                ->orderByDesc('version')])
            ->orderBy('name')
            ->get();

        return view('platform.products.index', [
            'products' => $products,
            'productManifests' => $productRegistry->all(),
        ]);
    }

    public function updateLifecycle(Request $request, Product $product, ProductRegistry $productRegistry): RedirectResponse
    {
        $manifest = $productRegistry->find($product->product_code);
        abort_unless($manifest !== null, 404);

        $validated = $request->validateWithBag('productLifecycle', [
            'lifecycle' => ['required', Rule::in(['active', 'maintenance', 'retired'])],
        ]);

        if ($validated['lifecycle'] === 'active' && $manifest['lifecycle'] !== 'active') {
            throw ValidationException::withMessages([
                'lifecycle' => '程式登錄的產品狀態尚未允許啟用。',
            ])->errorBag('productLifecycle');
        }

        $previousLifecycle = $product->lifecycle;
        $product->update(['lifecycle' => $validated['lifecycle']]);

        if ($previousLifecycle !== $product->lifecycle) {
            $this->auditLogger->record(PlatformAuditAction::ProductLifecycleUpdated, $product, [
                'product_code' => $product->product_code,
                'changes' => $this->auditLogger->changes(
                    ['lifecycle' => $previousLifecycle],
                    ['lifecycle' => $product->lifecycle],
                ),
            ]);
        }

        return to_route('platform.products.index')->with('status', '產品狀態已更新。');
    }

    public function storePlan(Request $request, Product $product): RedirectResponse
    {
        $features = $product->features()->orderBy('id')->get();
        abort_if($features->isEmpty(), 409, '產品尚未同步功能清單。');
        $planCodeInput = $request->input('plan_code');
        $planCode = is_string($planCodeInput) ? $planCodeInput : '';

        $validator = Validator::make($request->all(), [
            'product_id' => ['required', 'integer', Rule::in([(string) $product->getKey()])],
            'plan_code' => ['required', 'string', 'max:80', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'version' => [
                'required', 'integer', 'min:1', 'max:65535',
                Rule::unique('product_plans')->where(fn (Builder $query) => $query
                    ->where('product_id', $product->getKey())
                    ->where('plan_code', $planCode)),
            ],
            'name' => ['required', 'string', 'max:120'],
            'status' => ['required', Rule::in(['draft', 'active'])],
            'billing_interval' => ['required', Rule::in(['monthly', 'yearly', 'one_time', 'custom'])],
            'price' => ['nullable', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?\z/'],
            'currency_code' => ['nullable', Rule::in(['TWD', 'USD'])],
            'features' => ['required', 'array'],
            'features.*.is_enabled' => ['required', 'boolean'],
            'features.*.limit_value' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
        ], [
            'plan_code.regex' => '代碼僅能使用小寫英文字母、數字與連字號。',
            'version.unique' => '此產品已存在相同方案代碼與版本。',
            'price.regex' => '價格請輸入最多 12 位整數與 2 位小數。',
            'features.required' => '請設定方案功能。',
        ]);

        $validator->after(function ($validator) use ($request, $features, $product): void {
            $submittedFeatures = $request->input('features');
            $allowedFeatureIds = $features->modelKeys();
            $submittedFeatureIds = is_array($submittedFeatures) ? array_map('intval', array_keys($submittedFeatures)) : [];

            sort($allowedFeatureIds);
            sort($submittedFeatureIds);

            if ($allowedFeatureIds !== $submittedFeatureIds) {
                $validator->errors()->add('features', '方案功能清單與產品登錄的功能不一致，請重新載入頁面。');
            }

            $price = $request->input('price');
            $currency = $request->input('currency_code');

            if (($price === null) !== ($currency === null)) {
                $validator->errors()->add('price', '請同時設定價格與幣別，或兩者皆留空。');
            }

            $enabledFeatureCount = is_array($submittedFeatures)
                ? collect($submittedFeatures)->filter(static fn (mixed $feature): bool => is_array($feature)
                    && filter_var($feature['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN))->count()
                : 0;

            if ($request->input('status') === 'active' && ($price === null || $enabledFeatureCount === 0)) {
                $validator->errors()->add('status', '上架方案需要設定價格及至少一項啟用功能。');
            }

            if ($request->input('status') === 'active' && $product->lifecycle !== 'active') {
                $validator->errors()->add('status', '產品維護或停售時，不可上架新方案。');
            }

            if (is_array($submittedFeatures)) {
                foreach ($submittedFeatures as $featureId => $feature) {
                    if (is_array($feature)
                        && filter_var($feature['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) === false
                        && ($feature['limit_value'] ?? null) !== null) {
                        $validator->errors()->add("features.{$featureId}.limit_value", '停用的功能不可設定用量上限。');
                    }
                }
            }
        });

        if ($validator->fails()) {
            throw (new ValidationException($validator))->errorBag('productPlan');
        }

        /** @var array{plan_code: string, version: int|string, name: string, status: string, billing_interval: string, price?: string|null, currency_code?: string|null, features: array<int|string, array{is_enabled: bool|string|int, limit_value?: int|string|null}>} $validated */
        $validated = $validator->validated();

        DB::transaction(function () use ($product, $features, $validated): void {
            Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            if ($product->plans()
                ->where('plan_code', $validated['plan_code'])
                ->where('version', $validated['version'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'version' => '此產品已存在相同方案代碼與版本。',
                ])->errorBag('productPlan');
            }

            $plan = $product->plans()->create([
                'plan_code' => trim($validated['plan_code']),
                'version' => (int) $validated['version'],
                'name' => trim($validated['name']),
                'status' => $validated['status'],
                'billing_interval' => $validated['billing_interval'],
                'amount_minor' => isset($validated['price']) ? $this->toMinorUnits($validated['price']) : null,
                'currency_code' => $validated['currency_code'] ?? null,
            ]);

            foreach ($features as $feature) {
                $settings = $validated['features'][$feature->getKey()];
                $isEnabled = filter_var($settings['is_enabled'], FILTER_VALIDATE_BOOLEAN);

                $plan->entitlements()->create([
                    'product_id' => $product->getKey(),
                    'feature_key' => $feature->feature_key,
                    'is_enabled' => $isEnabled,
                    'limit_value' => $isEnabled && ($settings['limit_value'] ?? null) !== null
                        ? (int) $settings['limit_value']
                        : null,
                ]);
            }

            $this->auditLogger->record(PlatformAuditAction::ProductPlanCreated, $plan, [
                'product_code' => $product->product_code,
                'plan_code' => $plan->plan_code,
                'version' => $plan->version,
                'status' => $plan->status,
                'billing_interval' => $plan->billing_interval,
                'amount_minor' => $plan->amount_minor,
                'currency_code' => $plan->currency_code,
            ]);
        });

        return to_route('platform.products.index')->with('status', '產品方案已建立。');
    }

    public function updatePlan(Request $request, ProductPlan $productPlan): RedirectResponse
    {
        $validated = $request->validateWithBag('productPlanStatus', [
            'status' => ['required', Rule::in(['active', 'retired'])],
        ]);

        if ($validated['status'] === 'active') {
            if ($productPlan->amount_minor === null
                || $productPlan->currency_code === null
                || ! $productPlan->entitlements()->where('is_enabled', true)->exists()
                || $productPlan->product()->where('lifecycle', 'active')->doesntExist()) {
                throw ValidationException::withMessages([
                    'status' => '上架方案需有價格、至少一項啟用功能，且產品必須為啟用狀態。',
                ])->errorBag('productPlanStatus');
            }
        }

        $previousStatus = $productPlan->status;
        $productPlan->update(['status' => $validated['status']]);

        if ($previousStatus !== $productPlan->status) {
            $this->auditLogger->record(PlatformAuditAction::ProductPlanStatusUpdated, $productPlan, [
                'plan_code' => $productPlan->plan_code,
                'version' => $productPlan->version,
                'changes' => $this->auditLogger->changes(
                    ['status' => $previousStatus],
                    ['status' => $productPlan->status],
                ),
            ]);
        }

        return to_route('platform.products.index')->with('status', '方案狀態已更新。');
    }

    private function toMinorUnits(string $price): int
    {
        [$wholeUnits, $fractionalUnits] = array_pad(explode('.', $price, 2), 2, '');

        return ((int) $wholeUnits * 100) + (int) str_pad($fractionalUnits, 2, '0');
    }
}
