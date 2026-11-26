<?php

namespace App\Models;

use Database\Factories\MetaMarketingClientAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['meta_marketing_connection_id', 'client_id', 'ad_account_id', 'ad_account_name', 'currency_code'])]
#[Hidden(['workspace_id'])]
class MetaMarketingClientAccount extends Model
{
    use BelongsToAuthenticatedClientWorkspace;

    /** @use HasFactory<MetaMarketingClientAccountFactory> */
    use HasFactory;

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MetaMarketingConnection::class, 'meta_marketing_connection_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
