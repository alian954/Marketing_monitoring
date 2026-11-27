<?php

namespace App\Models;

use Database\Factories\ClientTrackedKeywordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A search query whose Google ranking is tracked weekly from the client's Search Console data.
 */
#[Fillable(['keyword', 'target_position'])]
class ClientTrackedKeyword extends Model
{
    /** @use HasFactory<ClientTrackedKeywordFactory> */
    use HasFactory;

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function rankings(): HasMany
    {
        return $this->hasMany(ClientKeywordRanking::class);
    }

    protected function casts(): array
    {
        return [
            'target_position' => 'integer',
            'last_synced_at' => 'immutable_datetime',
        ];
    }
}
