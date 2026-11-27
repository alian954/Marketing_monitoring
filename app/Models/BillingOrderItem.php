<?php

namespace App\Models;

use Database\Factories\BillingOrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['billing_order_id', 'organization_id', 'product_id', 'product_plan_id', 'description', 'quantity', 'unit_amount_minor', 'amount_minor', 'service_starts_on', 'service_ends_on'])]
class BillingOrderItem extends Model
{
    /** @use HasFactory<BillingOrderItemFactory> */
    use HasFactory;

    public function order(): BelongsTo
    {
        return $this->belongsTo(BillingOrder::class, 'billing_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductPlan::class, 'product_plan_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount_minor' => 'integer',
            'amount_minor' => 'integer',
            'service_starts_on' => 'immutable_date',
            'service_ends_on' => 'immutable_date',
        ];
    }
}
