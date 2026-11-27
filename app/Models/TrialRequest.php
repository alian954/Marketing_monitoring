<?php

namespace App\Models;

use Database\Factories\TrialRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'product_id', 'product_plan_id', 'requested_by_user_id', 'status', 'message', 'trial_days', 'reviewed_by_user_id', 'reviewed_at', 'review_note', 'subscription_id'])]
class TrialRequest extends Model
{
    /** @use HasFactory<TrialRequestFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const DEFAULT_TRIAL_DAYS = 14;

    public const MAX_TRIAL_DAYS = 90;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductPlan::class, 'product_plan_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => '審核中',
            self::STATUS_APPROVED => '已核准',
            self::STATUS_REJECTED => '未核准',
            default => '未知狀態',
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trial_days' => 'integer',
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
