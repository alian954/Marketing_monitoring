<?php

namespace App\Models;

use Database\Factories\IntegrationApplicationCredentialEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'actor_user_id', 'provider_code', 'action'])]
class IntegrationApplicationCredentialEvent extends Model
{
    /** @use HasFactory<IntegrationApplicationCredentialEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;
}
