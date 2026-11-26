<?php

namespace App\Models;

use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'public_id'])]
#[Hidden(['organization_id'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
