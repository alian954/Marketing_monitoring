<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['product_code', 'name', 'version', 'manifest_version', 'lifecycle', 'entry_route'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    public function features(): HasMany
    {
        return $this->hasMany(ProductFeature::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(ProductPlan::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'manifest_version' => 'integer',
        ];
    }
}
