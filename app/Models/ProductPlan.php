<?php

namespace App\Models;

use Database\Factories\ProductPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['product_id', 'plan_code', 'version', 'name', 'status', 'billing_interval', 'amount_minor', 'currency_code'])]
class ProductPlan extends Model
{
    /** @use HasFactory<ProductPlanFactory> */
    use HasFactory;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }

    public function subscriptionItems(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'amount_minor' => 'integer',
        ];
    }
}
