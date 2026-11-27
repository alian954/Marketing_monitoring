<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Seven-day Search Console metrics for a tracked keyword, dated by the last day of the window.
 */
#[Fillable(['measured_on', 'position', 'clicks', 'impressions', 'ctr_percent'])]
class ClientKeywordRanking extends Model
{
    public function keyword(): BelongsTo
    {
        return $this->belongsTo(ClientTrackedKeyword::class, 'client_tracked_keyword_id');
    }

    protected function casts(): array
    {
        return [
            'measured_on' => 'immutable_date',
            'position' => 'decimal:2',
            'clicks' => 'integer',
            'impressions' => 'integer',
            'ctr_percent' => 'decimal:2',
        ];
    }
}
