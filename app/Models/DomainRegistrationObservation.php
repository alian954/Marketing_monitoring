<?php

namespace App\Models;

use Database\Factories\DomainRegistrationObservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['monitor_id', 'observed_on', 'domain_name', 'expires_at', 'error_type'])]
class DomainRegistrationObservation extends Model
{
    /** @use HasFactory<DomainRegistrationObservationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['observed_on' => 'immutable_date', 'expires_at' => 'immutable_datetime'];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
