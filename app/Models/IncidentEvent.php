<?php

namespace App\Models;

use Database\Factories\IncidentEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['incident_id', 'check_run_id', 'event_type', 'evidence'])]
class IncidentEvent extends Model
{
    public $timestamps = false;

    /** @use HasFactory<IncidentEventFactory> */
    use HasFactory;

    use ScopesThroughAuthenticatedIncident;

    protected function casts(): array
    {
        return ['evidence' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function checkRun(): BelongsTo
    {
        return $this->belongsTo(CheckRun::class);
    }
}
