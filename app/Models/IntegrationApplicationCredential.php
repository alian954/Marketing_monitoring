<?php

namespace App\Models;

use Database\Factories\IntegrationApplicationCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'provider_code', 'client_id', 'client_secret', 'last_updated_by_user_id'])]
#[Hidden(['client_secret'])]
class IntegrationApplicationCredential extends Model
{
    /** @use HasFactory<IntegrationApplicationCredentialFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
        ];
    }
}
