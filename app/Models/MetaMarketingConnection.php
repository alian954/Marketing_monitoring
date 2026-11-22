<?php

namespace App\Models;

use Database\Factories\MetaMarketingConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'access_token', 'token_expires_at', 'ad_account_id', 'ad_account_name', 'currency_code'])]
#[Hidden(['access_token'])]
class MetaMarketingConnection extends Model
{
    /** @use HasFactory<MetaMarketingConnectionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'token_expires_at' => 'immutable_datetime',
        ];
    }
}
