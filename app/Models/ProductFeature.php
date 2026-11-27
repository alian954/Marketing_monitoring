<?php

namespace App\Models;

use Database\Factories\ProductFeatureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'feature_key', 'display_name'])]
class ProductFeature extends Model
{
    /** @use HasFactory<ProductFeatureFactory> */
    use HasFactory;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
