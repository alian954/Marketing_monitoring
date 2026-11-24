<?php

namespace App\Models;

use Database\Factories\ClientExchangeRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['client_id', 'source_currency_code', 'reporting_currency_code', 'effective_on', 'rate_to_reporting_currency'])]
class ClientExchangeRate extends Model
{
    /** @use HasFactory<ClientExchangeRateFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new AuthenticatedRelationScope('client'));
    }

    protected function casts(): array
    {
        return [
            'effective_on' => 'immutable_date',
            'rate_to_reporting_currency' => 'decimal:8',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
