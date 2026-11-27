<?php

namespace App\Models;

use Database\Factories\BillingPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['billing_order_id', 'organization_id', 'method', 'amount_minor', 'currency_code', 'received_at', 'reference', 'recorded_by_user_id'])]
class BillingPayment extends Model
{
    public const METHOD_BANK_TRANSFER = 'manual_bank_transfer';

    public const METHOD_CASH = 'manual_cash';

    public const METHOD_OTHER = 'manual_other';

    public const METHODS = [self::METHOD_BANK_TRANSFER, self::METHOD_CASH, self::METHOD_OTHER];

    public const UPDATED_AT = null;

    /** @use HasFactory<BillingPaymentFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Billing payments are append-only; record a correction instead.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Billing payments are append-only; record a correction instead.');
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

    public static function methodLabel(string $method): string
    {
        return match ($method) {
            self::METHOD_BANK_TRANSFER => '銀行轉帳',
            self::METHOD_CASH => '現金',
            self::METHOD_OTHER => '其他人工收款',
            default => $method,
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'received_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
