<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['social_engagement_target_id', 'source', 'observed_on', 'observed_value', 'currency_code', 'message'])]
class MarketingAlertEvent extends Model
{
    public function rule(): BelongsTo
    {
        return $this->belongsTo(MarketingAlertRule::class, 'marketing_alert_rule_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(SocialEngagementTarget::class, 'social_engagement_target_id');
    }

    protected function casts(): array
    {
        return [
            'observed_on' => 'immutable_date',
            'observed_value' => 'decimal:4',
        ];
    }
}
