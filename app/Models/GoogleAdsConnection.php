<?php

namespace App\Models;

use Database\Factories\GoogleAdsConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'refresh_token', 'customer_id', 'login_customer_id', 'customer_name', 'currency_code'])]
#[Hidden(['refresh_token'])]
class GoogleAdsConnection extends Model
{
    /** @use HasFactory<GoogleAdsConnectionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
        ];
    }
}
