<?php

namespace App\Models;

use Database\Factories\BillingRefundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['billing_order_id', 'organization_id', 'method', 'amount_minor', 'currency_code', 'refunded_at', 'reason', 'reference', 'recorded_by_user_id'])]
class BillingRefund extends Model
{
    public const UPDATED_AT = null;

    /** @use HasFactory<BillingRefundFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Billing refunds are append-only.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Billing refunds are append-only.');
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(BillingOrder::class, 'billing_order_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'refunded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
