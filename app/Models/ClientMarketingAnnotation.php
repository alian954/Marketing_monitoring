<?php

namespace App\Models;

use Database\Factories\ClientMarketingAnnotationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dated note on a client's marketing calendar, such as a budget change or a promotion launch,
 * used to explain movements in performance charts.
 */
#[Fillable(['occurred_on', 'category', 'title', 'note', 'created_by_user_id'])]
class ClientMarketingAnnotation extends Model
{
    public const CATEGORY_BUDGET = 'budget';

    public const CATEGORY_CREATIVE = 'creative';

    public const CATEGORY_PROMOTION = 'promotion';

    public const CATEGORY_TARGETING = 'targeting';

    public const CATEGORY_WEBSITE = 'website';

    public const CATEGORY_TRACKING = 'tracking';

    public const CATEGORY_OTHER = 'other';

    /** @var array<string, string> */
    public const CATEGORIES = [
        self::CATEGORY_BUDGET => '預算調整',
        self::CATEGORY_CREATIVE => '素材上線或更換',
        self::CATEGORY_PROMOTION => '檔期或促銷',
        self::CATEGORY_TARGETING => '受眾或出價調整',
        self::CATEGORY_WEBSITE => '網站或到達頁改版',
        self::CATEGORY_TRACKING => '追蹤設定變更',
        self::CATEGORY_OTHER => '其他',
    ];

    /** @use HasFactory<ClientMarketingAnnotationFactory> */
    use HasFactory;

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? self::CATEGORIES[self::CATEGORY_OTHER];
    }

    protected function casts(): array
    {
        return [
            'occurred_on' => 'immutable_date',
        ];
    }
}
