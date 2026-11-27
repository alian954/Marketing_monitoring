<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;

#[Fillable(['name', 'reporting_currency_code'])]
#[Hidden(['user_id', 'workspace_id'])]
class Client extends Model
{
    public const DEFAULT_NAME = '未分類';

    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new AuthenticatedWorkspaceScope);
        static::addGlobalScope(new WorkspaceClientScope);

        static::creating(static function (Client $client): void {
            $user = Auth::user();

            if ($user instanceof User) {
                if ($client->user_id === null) {
                    $client->forceFill(['user_id' => $user->workspaceOwnerId()]);
                }
            } elseif (is_numeric($client->user_id)) {
                $user = User::query()->find((int) $client->user_id);
            }

            if ($user instanceof User && $client->workspace_id === null) {
                $client->forceFill(['workspace_id' => $user->currentWorkspace()?->getKey()]);
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    public function socialEngagementTargets(): HasMany
    {
        return $this->hasMany(SocialEngagementTarget::class);
    }

    public function reportShares(): HasMany
    {
        return $this->hasMany(ClientReportShare::class);
    }

    public function reportTemplates(): HasMany
    {
        return $this->hasMany(ClientReportTemplate::class);
    }

    public function reportSchedules(): HasMany
    {
        return $this->hasMany(ClientReportSchedule::class);
    }

    public function marketingAlertRules(): HasMany
    {
        return $this->hasMany(MarketingAlertRule::class);
    }

    public function marketingAnnotations(): HasMany
    {
        return $this->hasMany(ClientMarketingAnnotation::class);
    }

    public function monthlyBudgets(): HasMany
    {
        return $this->hasMany(ClientMonthlyBudget::class);
    }

    public function exchangeRates(): HasMany
    {
        return $this->hasMany(ClientExchangeRate::class);
    }

    public function googleSearchConsoleSite(): HasOne
    {
        return $this->hasOne(GoogleSearchConsoleClientSite::class);
    }

    public function googleAnalyticsProperty(): HasOne
    {
        return $this->hasOne(GoogleAnalyticsClientProperty::class);
    }

    public function metaMarketingAccount(): HasOne
    {
        return $this->hasOne(MetaMarketingClientAccount::class);
    }

    public function googleAdsAccount(): HasOne
    {
        return $this->hasOne(GoogleAdsClientAccount::class);
    }

    public function isDefault(): bool
    {
        return $this->name === self::DEFAULT_NAME;
    }

    public static function ensureDefaultFor(User $user): self
    {
        $membership = $user->workspaceMembership;

        if ($membership?->client_scope === WorkspaceMembership::CLIENT_SCOPE_SELECTED) {
            return $membership->clients()->orderBy('name')->firstOrFail();
        }

        return $user->clients()->firstOrCreate(['name' => self::DEFAULT_NAME]);
    }
}
