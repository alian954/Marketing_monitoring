<?php

namespace App\Models;

use Database\Factories\SocialEngagementTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tag', 'social_platform', 'target_interactions', 'starts_on', 'ends_on', 'ga4_campaign_name', 'ga4_source_name', 'meta_campaign_id', 'google_ads_campaign_id', 'ad_spend_amount', 'ad_spend_currency', 'campaign_budget_amount', 'campaign_budget_currency'])]
class SocialEngagementTarget extends Model
{
    /** @use HasFactory<SocialEngagementTargetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'target_interactions' => 'integer',
            'ad_spend_amount' => 'decimal:4',
            'campaign_budget_amount' => 'decimal:4',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    public function googleAnalyticsCampaignName(): string
    {
        return is_string($this->ga4_campaign_name) && trim($this->ga4_campaign_name) !== ''
            ? trim($this->ga4_campaign_name)
            : $this->tag;
    }

    public function googleAnalyticsSourceName(): ?string
    {
        return is_string($this->ga4_source_name) && trim($this->ga4_source_name) !== ''
            ? trim($this->ga4_source_name)
            : null;
    }
}
