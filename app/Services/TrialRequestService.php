<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\PlatformAuditAction;
use App\Models\ProductPlan;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\TrialRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lets organization owners request a product trial and lets platform administrators review it.
 *
 * An organization may trial a product only while it has never held that product (subscription items are unique per
 * organization and product), so each product can be trialed at most once. Approval creates a separate trialing
 * subscription whose access ends at the trial end; a later paid order converts it through the subscription provisioner.
 */
class TrialRequestService
{
    public function __construct(private readonly PlatformAuditLogger $auditLogger) {}

    /**
     * Plans an organization may request a trial for: active plans of products that are active in the registry and catalog.
     *
     * @return Builder<ProductPlan>
     */
    public function trialablePlans(): Builder
    {
        $activeProductCodes = collect(app(ProductRegistry::class)->all())
            ->filter(static fn (array $manifest): bool => $manifest['lifecycle'] === 'active')
            ->keys()
            ->all();

        return ProductPlan::query()
            ->where('status', 'active')
            ->whereHas('product', static fn (Builder $query): Builder => $query
                ->where('lifecycle', 'active')
                ->whereIn('product_code', $activeProductCodes));
    }

    /**
     * Why the organization cannot request a trial for the product, or null when it can.
     */
    public function unavailableReason(Organization $organization, int $productId): ?string
    {
        if (SubscriptionItem::query()
            ->where('organization_id', $organization->getKey())
            ->where('product_id', $productId)
            ->exists()) {
            return '組織已訂閱或試用過此產品。';
        }

        if (TrialRequest::query()
            ->where('organization_id', $organization->getKey())
            ->where('product_id', $productId)
            ->where('status', TrialRequest::STATUS_PENDING)
            ->exists()) {
            return '此產品的試用申請正在審核中。';
        }

        return null;
    }

    public function request(Organization $organization, int $productPlanId, ?string $message, User $requester): TrialRequest
    {
        return DB::transaction(function () use ($organization, $productPlanId, $message, $requester): TrialRequest {
            Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

            $plan = $this->trialablePlans()->whereKey($productPlanId)->first();

            if (! $plan instanceof ProductPlan) {
                throw ValidationException::withMessages(['product_plan_id' => '請選擇目前開放試用的產品方案。']);
            }

            $reason = $this->unavailableReason($organization, (int) $plan->product_id);

            if ($reason !== null) {
                throw ValidationException::withMessages(['product_plan_id' => $reason]);
            }

            $trialRequest = $organization->trialRequests()->create([
                'product_id' => $plan->product_id,
                'product_plan_id' => $plan->getKey(),
                'requested_by_user_id' => $requester->getKey(),
                'status' => TrialRequest::STATUS_PENDING,
                'message' => $message,
            ]);

            $this->auditLogger->record(PlatformAuditAction::TrialRequested, $trialRequest, [
                'product_id' => $plan->product_id,
                'product_plan_id' => $plan->getKey(),
            ], (int) $organization->owner_user_id, $requester);

            return $trialRequest;
        });
    }

    public function approve(TrialRequest $trialRequest, int $trialDays, ?string $reviewNote, User $reviewer): Subscription
    {
        return DB::transaction(function () use ($trialRequest, $trialDays, $reviewNote, $reviewer): Subscription {
            $organization = Organization::query()->whereKey($trialRequest->organization_id)->lockForUpdate()->firstOrFail();
            $lockedRequest = $this->lockPendingRequest($trialRequest);

            if (! $this->trialablePlans()->whereKey($lockedRequest->product_plan_id)->exists()) {
                throw ValidationException::withMessages(['trial_days' => '申請的方案目前未開放，請婉拒此申請或先啟用方案。']);
            }

            if (SubscriptionItem::query()
                ->where('organization_id', $organization->getKey())
                ->where('product_id', $lockedRequest->product_id)
                ->exists()) {
                throw ValidationException::withMessages(['trial_days' => '組織已有此產品的訂閱紀錄，無法再開通試用。']);
            }

            $startsAt = now();
            $endsAt = $startsAt->copy()->addDays($trialDays);

            $subscription = Subscription::query()->create([
                'organization_id' => $organization->getKey(),
                'status' => 'trialing',
                'starts_at' => $startsAt,
                'current_period_starts_at' => $startsAt,
                'current_period_ends_at' => $endsAt,
                'trial_ends_at' => $endsAt,
                'cancel_at_period_end' => false,
            ]);
            $item = $subscription->items()->create([
                'organization_id' => $organization->getKey(),
                'product_id' => $lockedRequest->product_id,
                'product_plan_id' => $lockedRequest->product_plan_id,
                'status' => 'active',
                'quantity' => 1,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            $lockedRequest->update([
                'status' => TrialRequest::STATUS_APPROVED,
                'trial_days' => $trialDays,
                'reviewed_by_user_id' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_note' => $reviewNote,
                'subscription_id' => $subscription->getKey(),
            ]);

            $ownerUserId = (int) $organization->owner_user_id;
            $this->auditLogger->record(PlatformAuditAction::SubscriptionCreated, $subscription, [
                'status' => 'trialing',
                'starts_at' => $startsAt->format(DATE_ATOM),
                'trial_ends_at' => $endsAt->format(DATE_ATOM),
                'trial_request_id' => $lockedRequest->getKey(),
            ], $ownerUserId, $reviewer);
            $this->auditLogger->record(PlatformAuditAction::SubscriptionItemCreated, $item, [
                'subscription_id' => $subscription->getKey(),
                'product_id' => $item->product_id,
                'product_plan_id' => $item->product_plan_id,
                'quantity' => 1,
                'starts_at' => $startsAt->format(DATE_ATOM),
                'ends_at' => $endsAt->format(DATE_ATOM),
            ], $ownerUserId, $reviewer);
            $this->auditLogger->record(PlatformAuditAction::TrialApproved, $lockedRequest, [
                'product_id' => $lockedRequest->product_id,
                'product_plan_id' => $lockedRequest->product_plan_id,
                'trial_days' => $trialDays,
                'trial_ends_at' => $endsAt->format(DATE_ATOM),
            ], $ownerUserId, $reviewer);

            return $subscription;
        });
    }

    public function reject(TrialRequest $trialRequest, string $reviewNote, User $reviewer): void
    {
        DB::transaction(function () use ($trialRequest, $reviewNote, $reviewer): void {
            $lockedRequest = $this->lockPendingRequest($trialRequest);
            $lockedRequest->update([
                'status' => TrialRequest::STATUS_REJECTED,
                'reviewed_by_user_id' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_note' => $reviewNote,
            ]);

            $this->auditLogger->record(PlatformAuditAction::TrialRejected, $lockedRequest, [
                'product_id' => $lockedRequest->product_id,
                'product_plan_id' => $lockedRequest->product_plan_id,
                'review_note' => $reviewNote,
            ], (int) $lockedRequest->organization()->value('owner_user_id'), $reviewer);
        });
    }

    private function lockPendingRequest(TrialRequest $trialRequest): TrialRequest
    {
        $lockedRequest = TrialRequest::query()->whereKey($trialRequest->getKey())->lockForUpdate()->firstOrFail();

        if (! $lockedRequest->isPending()) {
            throw ValidationException::withMessages(['trial_days' => '此試用申請已審核，請重新整理頁面。']);
        }

        return $lockedRequest;
    }
}
