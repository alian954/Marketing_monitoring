<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\PlatformAuditAction;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Services\PlatformAuditLogger;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Retained as archived compatibility tooling while commercial subscriptions are deferred.
 */
class PlatformSubscriptionController extends Controller
{
    private const AUDITED_SUBSCRIPTION_FIELDS = [
        'status', 'starts_at', 'current_period_starts_at', 'current_period_ends_at', 'trial_ends_at', 'cancel_at_period_end',
    ];

    private const AUDITED_SUBSCRIPTION_ITEM_FIELDS = ['status', 'product_plan_id', 'quantity', 'starts_at', 'ends_at'];

    public function __construct(private PlatformAuditLogger $auditLogger) {}

    public function index(Organization $organization): View
    {
        $products = Product::query()
            ->with(['plans' => fn ($query) => $query
                ->orderBy('name')
                ->orderByDesc('version')])
            ->orderBy('name')
            ->get();
        $subscriptions = $organization->subscriptions()
            ->with(['items.product', 'items.plan.entitlements'])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();

        return view('platform.organizations.products', [
            'organization' => $organization,
            'products' => $products,
            'subscriptions' => $subscriptions,
        ]);
    }

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validateWithBag('subscription', [
            'status' => ['required', Rule::in(['pending', 'trialing', 'active'])],
            'starts_at' => ['required', 'date'],
            'current_period_starts_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'current_period_ends_at' => ['nullable', 'date', 'after:current_period_starts_at'],
            'trial_ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        if ($validated['status'] === 'trialing' && empty($validated['trial_ends_at'])) {
            throw ValidationException::withMessages([
                'trial_ends_at' => '試用訂閱需要設定試用到期時間。',
            ])->errorBag('subscription');
        }

        $validated = $this->taipeiInputsToUtc($validated);

        $subscription = $organization->subscriptions()->create([
            ...$validated,
            'cancel_at_period_end' => false,
        ]);
        $this->auditLogger->record(
            PlatformAuditAction::SubscriptionCreated,
            $subscription,
            $this->auditedFields($subscription, self::AUDITED_SUBSCRIPTION_FIELDS),
            (int) $organization->owner_user_id,
        );

        return to_route('platform.organizations.products', $organization)->with('status', '訂閱已建立。');
    }

    public function update(Request $request, Organization $organization, Subscription $subscription): RedirectResponse
    {
        abort_unless((int) $subscription->organization_id === (int) $organization->getKey(), 404);

        $validated = $request->validateWithBag('subscriptionState', [
            'status' => ['required', Rule::in(['pending', 'trialing', 'active', 'past_due', 'paused', 'canceled', 'expired'])],
            'starts_at' => ['required', 'date'],
            'current_period_starts_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'current_period_ends_at' => ['nullable', 'date', 'after:current_period_starts_at'],
            'trial_ends_at' => ['nullable', 'date', 'after:starts_at'],
            'cancel_at_period_end' => ['required', 'boolean'],
        ]);

        if ($validated['status'] === 'trialing' && empty($validated['trial_ends_at'])) {
            throw ValidationException::withMessages([
                'trial_ends_at' => '試用訂閱需要設定試用到期時間。',
            ])->errorBag('subscriptionState');
        }

        if (filter_var($validated['cancel_at_period_end'], FILTER_VALIDATE_BOOLEAN)
            && empty($validated['current_period_ends_at'])) {
            throw ValidationException::withMessages([
                'current_period_ends_at' => '設定期末取消時，必須先設定目前週期到期時間。',
            ])->errorBag('subscriptionState');
        }

        $validated = $this->taipeiInputsToUtc($validated);

        $fieldsBefore = $this->auditedFields($subscription, self::AUDITED_SUBSCRIPTION_FIELDS);
        $subscription->update([
            ...$validated,
            'canceled_at' => $validated['status'] === 'canceled' ? ($subscription->canceled_at ?? now()) : null,
        ]);
        $this->recordChanges(
            PlatformAuditAction::SubscriptionUpdated,
            $subscription,
            $fieldsBefore,
            $this->auditedFields($subscription->refresh(), self::AUDITED_SUBSCRIPTION_FIELDS),
            $organization,
        );

        return to_route('platform.organizations.products', $organization)->with('status', '訂閱狀態已更新。');
    }

    public function storeItem(Request $request, Organization $organization, Subscription $subscription): RedirectResponse
    {
        abort_unless((int) $subscription->organization_id === (int) $organization->getKey(), 404);

        $validated = $request->validateWithBag('subscriptionItem', [
            'product_plan_id' => ['required', 'integer', 'exists:product_plans,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:65535'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        $validated = $this->taipeiInputsToUtc($validated);

        DB::transaction(function () use ($validated, $organization, $subscription): void {
            Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $lockedSubscription = Subscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();
            $plan = ProductPlan::query()
                ->whereKey($validated['product_plan_id'])
                ->where('status', 'active')
                ->whereNotNull('amount_minor')
                ->whereNotNull('currency_code')
                ->whereHas('product', fn (Builder $query) => $query->where('lifecycle', 'active'))
                ->first();

            if (! $plan instanceof ProductPlan) {
                throw ValidationException::withMessages([
                    'product_plan_id' => '請選擇目前可銷售的產品方案。',
                ])->errorBag('subscriptionItem');
            }

            if (! in_array($lockedSubscription->status, ['pending', 'trialing', 'active'], true)) {
                throw ValidationException::withMessages([
                    'product_plan_id' => '此訂閱狀態不可新增產品。',
                ])->errorBag('subscriptionItem');
            }

            if ($organization->subscriptionItems()->where('product_id', $plan->product_id)->exists()) {
                throw ValidationException::withMessages([
                    'product_plan_id' => '組織已訂閱此產品。請修改現有產品項目。',
                ])->errorBag('subscriptionItem');
            }

            $subscriptionItem = $lockedSubscription->items()->create([
                'organization_id' => $organization->getKey(),
                'product_id' => $plan->product_id,
                'product_plan_id' => $plan->getKey(),
                'status' => 'active',
                'quantity' => (int) $validated['quantity'],
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'] ?? null,
            ]);
            $this->auditLogger->record(
                PlatformAuditAction::SubscriptionItemCreated,
                $subscriptionItem,
                [
                    'subscription_id' => $lockedSubscription->getKey(),
                    'product_id' => $plan->product_id,
                    ...$this->auditedFields($subscriptionItem, self::AUDITED_SUBSCRIPTION_ITEM_FIELDS),
                ],
                (int) $organization->owner_user_id,
            );
        });

        return to_route('platform.organizations.products', $organization)->with('status', '產品已加入組織訂閱。');
    }

    public function updateItem(
        Request $request,
        Organization $organization,
        Subscription $subscription,
        SubscriptionItem $subscriptionItem,
    ): RedirectResponse {
        abort_unless((int) $subscription->organization_id === (int) $organization->getKey()
            && (int) $subscriptionItem->organization_id === (int) $organization->getKey()
            && (int) $subscriptionItem->subscription_id === (int) $subscription->getKey(), 404);

        $validated = $request->validateWithBag('subscriptionItemState', [
            'status' => ['required', Rule::in(['active', 'pending', 'suspended', 'canceled'])],
            'product_plan_id' => ['required', 'integer', 'exists:product_plans,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:65535'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        $plan = ProductPlan::query()
            ->whereKey($validated['product_plan_id'])
            ->where('product_id', $subscriptionItem->product_id)
            ->where(function (Builder $query) use ($subscriptionItem): void {
                $query->where('status', 'active')->orWhereKey($subscriptionItem->product_plan_id);
            })
            ->where(function (Builder $query) use ($subscriptionItem): void {
                $query->where(function (Builder $query): void {
                    $query->whereNotNull('amount_minor')->whereNotNull('currency_code');
                })->orWhereKey($subscriptionItem->product_plan_id);
            })
            ->first();

        if (! $plan instanceof ProductPlan
            || ($plan->getKey() !== $subscriptionItem->product_plan_id
                && ! $plan->product()->where('lifecycle', 'active')->exists())) {
            throw ValidationException::withMessages([
                'product_plan_id' => '請選擇此產品目前可銷售的方案。',
            ])->errorBag('subscriptionItemState');
        }

        $validated = $this->taipeiInputsToUtc($validated);

        $endsAt = $validated['ends_at'] ?? null;

        if ($validated['status'] === 'canceled' && $endsAt === null) {
            $startsAt = Carbon::parse($validated['starts_at']);
            $endsAt = $startsAt->lessThan(now()) ? now() : $startsAt;
        }

        $fieldsBefore = $this->auditedFields($subscriptionItem, self::AUDITED_SUBSCRIPTION_ITEM_FIELDS);
        $subscriptionItem->update([
            ...$validated,
            'ends_at' => $endsAt,
        ]);
        $this->recordChanges(
            PlatformAuditAction::SubscriptionItemUpdated,
            $subscriptionItem,
            $fieldsBefore,
            $this->auditedFields($subscriptionItem->refresh(), self::AUDITED_SUBSCRIPTION_ITEM_FIELDS),
            $organization,
        );

        return to_route('platform.organizations.products', $organization)->with('status', '產品訂閱項目已更新。');
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function auditedFields(Subscription|SubscriptionItem $model, array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $value = $model->getAttribute($field);
            $values[$field] = $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function recordChanges(
        PlatformAuditAction $action,
        Subscription|SubscriptionItem $subject,
        array $before,
        array $after,
        Organization $organization,
    ): void {
        $changes = $this->auditLogger->changes($before, $after);

        if ($changes !== []) {
            $this->auditLogger->record($action, $subject, ['changes' => $changes], (int) $organization->owner_user_id);
        }
    }

    /**
     * Datetime inputs are entered in Taiwan local time; store them in the application timezone (UTC).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function taipeiInputsToUtc(array $validated): array
    {
        foreach (['starts_at', 'ends_at', 'current_period_starts_at', 'current_period_ends_at', 'trial_ends_at'] as $field) {
            if (isset($validated[$field]) && is_string($validated[$field]) && $validated[$field] !== '') {
                $validated[$field] = Carbon::parse($validated[$field], 'Asia/Taipei')->setTimezone(config('app.timezone'));
            }
        }

        return $validated;
    }
}
