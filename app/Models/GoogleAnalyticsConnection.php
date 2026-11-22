<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'property_id', 'refresh_token'])]
class GoogleAnalyticsConnection extends Model
{
    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
        ];
    }
}
