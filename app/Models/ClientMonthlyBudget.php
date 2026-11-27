<?php

namespace App\Models;

use Database\Factories\ClientMonthlyBudgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client's planned paid-media budget for one calendar month (Asia/Taipei), optionally split by platform.
 */
#[Fillable(['month', 'currency_code', 'total_budget', 'google_ads_budget', 'meta_marketing_budget', 'note'])]
class ClientMonthlyBudget extends Model
{
    /** @use HasFactory<ClientMonthlyBudgetFactory> */
    use HasFactory;

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return array<string, float|null> */
    public function platformBudgets(): array
    {
        return [
            'google_ads' => $this->google_ads_budget === null ? null : (float) $this->google_ads_budget,
            'meta_marketing' => $this->meta_marketing_budget === null ? null : (float) $this->meta_marketing_budget,
        ];
    }

    protected function casts(): array
    {
        return [
            'total_budget' => 'decimal:2',
            'google_ads_budget' => 'decimal:2',
            'meta_marketing_budget' => 'decimal:2',
        ];
    }
}
