<?php

namespace App\Models;

use Database\Factories\StatusPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['setting_key', 'title', 'public_token', 'public_token_hash', 'is_published'])]
class StatusPage extends Model
{
    /** @use HasFactory<StatusPageFactory> */
    use HasFactory;

    protected $hidden = ['public_token', 'public_token_hash'];

    protected function casts(): array
    {
        return [
            'public_token' => 'encrypted',
            'is_published' => 'boolean',
        ];
    }
}
