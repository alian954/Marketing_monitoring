<?php

namespace App\Models;

use Database\Factories\ClientLeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sales lead imported from the client's CRM, used to judge ad platforms by qualified leads and
 * closed deals instead of raw form submissions.
 */
#[Fillable(['lead_reference', 'created_on', 'platform', 'campaign_name', 'stage', 'deal_value', 'won_on', 'currency_code', 'google_click_id'])]
#[Hidden(['google_click_id'])]
class ClientLead extends Model
{
    public const STAGE_NEW = 'new';

    public const STAGE_QUALIFIED = 'qualified';

    public const STAGE_WON = 'won';

    public const STAGE_LOST = 'lost';

    /** @var array<string, string> */
    public const STAGE_LABELS = [
        self::STAGE_NEW => '新名單',
        self::STAGE_QUALIFIED => '有效名單',
        self::STAGE_WON => '成交',
        self::STAGE_LOST => '無效',
    ];

    public const PLATFORM_OTHER = 'other';

    /** @use HasFactory<ClientLeadFactory> */
    use HasFactory;

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected function casts(): array
    {
        return [
            'created_on' => 'immutable_date',
            'won_on' => 'immutable_date',
            'deal_value' => 'decimal:2',
            'google_click_id' => 'encrypted',
        ];
    }
}
