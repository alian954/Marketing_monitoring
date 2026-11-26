<?php

namespace App\Models;

use Database\Factories\SocialEngagementTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

#[Fillable(['tag', 'social_platform', 'client_id', 'target_interactions', 'starts_on', 'ends_on', 'ga4_campaign_name', 'ga4_source_name', 'ga4_medium_name', 'ga4_goal_metric', 'ga4_goal_event_name', 'ga4_goal_value', 'ga4_goal_currency', 'paid_media_goal_source', 'paid_media_goal_roas', 'gross_margin_percent', 'meta_campaign_id', 'google_ads_campaign_id', 'ad_spend_amount', 'ad_spend_currency', 'campaign_budget_amount', 'campaign_budget_currency'])]
#[Hidden(['user_id', 'workspace_id'])]
class SocialEngagementTarget extends Model
{
    /** @use HasFactory<SocialEngagementTargetFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new AuthenticatedWorkspaceScope);
        static::addGlobalScope(new WorkspaceClientScope);
        static::addGlobalScope(new SelectedClientScope);

        static::creating(static function (SocialEngagementTarget $target): void {
            $user = Auth::user();

            if ($user instanceof User) {
                $target->forceFill(['user_id' => $user->workspaceOwnerId()]);
            } elseif (is_numeric($target->user_id)) {
                $user = User::query()->find((int) $target->user_id);
            }

            if ($user instanceof User && $target->client_id === null) {
                $target->forceFill(['client_id' => Client::ensureDefaultFor($user)->id]);
            }

            if ($user instanceof User && $target->workspace_id === null) {
                $workspaceId = $user->currentWorkspace()?->getKey();

                if ($workspaceId !== null) {
                    $target->forceFill(['workspace_id' => $workspaceId]);
                }
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected function casts(): array
    {
        return [
            'target_interactions' => 'integer',
            'ga4_goal_value' => 'decimal:4',
            'paid_media_goal_roas' => 'decimal:4',
            'gross_margin_percent' => 'decimal:4',
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

    public function googleAnalyticsMediumName(): ?string
    {
        return is_string($this->ga4_medium_name) && trim($this->ga4_medium_name) !== ''
            ? trim($this->ga4_medium_name)
            : null;
    }

    public function googleAnalyticsAttributionMode(): string
    {
        return is_string($this->google_ads_campaign_id) && trim($this->google_ads_campaign_id) !== ''
            ? 'google_ads_auto_tagging'
            : 'manual_utm';
    }
}
