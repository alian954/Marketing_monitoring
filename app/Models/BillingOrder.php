<?php

namespace App\Models;

use Database\Factories\BillingOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'order_number', 'status', 'currency_code', 'total_minor', 'issued_at', 'paid_at', 'canceled_at', 'cancellation_reason', 'notes', 'created_by_user_id'])]
class BillingOrder extends Model
{
    public const STATUS_ISSUED = 'issued';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_REFUNDED = 'refunded';

    /** @use HasFactory<BillingOrderFactory> */
    use HasFactory;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingOrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(BillingRefund::class);
    }

    public function refundedMinor(): int
    {
        return (int) $this->refunds()->sum('amount_minor');
    }

    /**
     * Amount collected and not refunded.
     */
    public function netPaidMinor(): int
    {
        return $this->paidMinor() - $this->refundedMinor();
    }

    public function paidMinor(): int
    {
        return (int) $this->payments()->sum('amount_minor');
    }

    public function balanceMinor(): int
    {
        return max(0, (int) $this->total_minor - $this->paidMinor());
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ISSUED => '待收款',
            self::STATUS_PAID => '已收款',
            self::STATUS_CANCELED => '已取消',
            self::STATUS_REFUNDED => '已退款',
            default => $this->status,
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'total_minor' => 'integer',
            'issued_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
        ];
    }
}
