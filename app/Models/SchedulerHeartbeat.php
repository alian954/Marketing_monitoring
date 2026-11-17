<?php

namespace App\Models;

use Database\Factories\SchedulerHeartbeatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['job_key', 'last_started_at', 'last_succeeded_at', 'last_error'])]
class SchedulerHeartbeat extends Model
{
    /** @use HasFactory<SchedulerHeartbeatFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['last_started_at' => 'immutable_datetime', 'last_succeeded_at' => 'immutable_datetime'];
    }
}
