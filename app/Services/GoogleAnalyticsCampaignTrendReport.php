<?php

namespace App\Services;

use App\Models\GoogleAnalyticsCampaignSnapshot;
use App\Models\GoogleAnalyticsConnection;
use App\Models\SocialEngagementTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class GoogleAnalyticsCampaignTrendReport
{
    private const DISPLAY_SNAPSHOT_COUNT = 30;

    /**
     * @return list<array{
     *     snapshot_date: CarbonImmutable,
     *     synced_at: CarbonImmutable,
     *     has_previous_snapshot: bool,
     *     sessions: int,
     *     sessions_change: int|null,
     *     engaged_sessions: int,
     *     engaged_sessions_change: int|null,
     *     key_events: string,
     *     key_events_change: float|null,
     *     total_revenue: string,
     *     total_revenue_change: float|null,
     *     currency_code: string|null,
     *     revenue_currency_comparable: bool
     * }>
     */
    public function recentChanges(
        GoogleAnalyticsConnection $connection,
        SocialEngagementTarget $target,
        string $campaignName,
        ?string $sourceName,
    ): array {
        if ($target->starts_on === null || $target->ends_on === null) {
            return [];
        }

        $snapshots = GoogleAnalyticsCampaignSnapshot::query()
            ->where('google_analytics_connection_id', $connection->getKey())
            ->where('social_engagement_target_id', $target->getKey())
            ->where('campaign_name', $campaignName)
            ->when($sourceName === null, fn (Builder $query): Builder => $query->whereNull('source_name'))
            ->when($sourceName !== null, fn (Builder $query): Builder => $query->where('source_name', $sourceName))
            ->whereDate('starts_on', $target->starts_on->toDateString())
            ->whereDate('ends_on', $target->ends_on->toDateString())
            ->orderByDesc('snapshot_date')
            ->limit(self::DISPLAY_SNAPSHOT_COUNT + 1)
            ->get()
            ->reverse()
            ->values();

        $previousSnapshot = $snapshots->count() > self::DISPLAY_SNAPSHOT_COUNT
            ? $snapshots->shift()
            : null;
        $changes = [];

        foreach ($snapshots as $snapshot) {
            $hasPreviousSnapshot = $previousSnapshot !== null;
            $revenueCurrencyComparable = $hasPreviousSnapshot
                && is_string($snapshot->currency_code)
                && $snapshot->currency_code !== ''
                && $snapshot->currency_code === $previousSnapshot->currency_code;

            $changes[] = [
                'snapshot_date' => CarbonImmutable::parse($snapshot->snapshot_date, 'Asia/Taipei'),
                'synced_at' => $snapshot->synced_at,
                'has_previous_snapshot' => $hasPreviousSnapshot,
                'sessions' => $snapshot->sessions,
                'sessions_change' => $hasPreviousSnapshot
                    ? $snapshot->sessions - $previousSnapshot->sessions
                    : null,
                'engaged_sessions' => $snapshot->engaged_sessions,
                'engaged_sessions_change' => $hasPreviousSnapshot
                    ? $snapshot->engaged_sessions - $previousSnapshot->engaged_sessions
                    : null,
                'key_events' => $snapshot->key_events,
                'key_events_change' => $hasPreviousSnapshot
                    ? round((float) $snapshot->key_events - (float) $previousSnapshot->key_events, 4)
                    : null,
                'total_revenue' => $snapshot->total_revenue,
                'total_revenue_change' => $revenueCurrencyComparable
                    ? round((float) $snapshot->total_revenue - (float) $previousSnapshot->total_revenue, 4)
                    : null,
                'currency_code' => $snapshot->currency_code,
                'revenue_currency_comparable' => $revenueCurrencyComparable,
            ];

            $previousSnapshot = $snapshot;
        }

        return $changes;
    }
}
