<?php

namespace App\Models;

use Database\Factories\ClientStoreOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An order (or a daily order total) imported from the client's online store, used as the
 * real revenue figure that ad-platform attributed revenue is compared against.
 */
#[Fillable(['order_reference', 'ordered_on', 'currency_code', 'revenue', 'orders_count', 'new_customer_orders', 'source_label'])]
class ClientStoreOrder extends Model
{
    /** @use HasFactory<ClientStoreOrderFactory> */
    use HasFactory;

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected function casts(): array
    {
        return [
            'ordered_on' => 'immutable_date',
            'revenue' => 'decimal:2',
            'orders_count' => 'integer',
            'new_customer_orders' => 'integer',
        ];
    }
}
