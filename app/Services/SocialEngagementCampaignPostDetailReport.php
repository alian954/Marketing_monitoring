<?php

namespace App\Services;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class SocialEngagementCampaignPostDetailReport
{
    private const HISTORY_TIMEZONE = 'Asia/Taipei';

    private const PAGE_SIZE = 25;

    /**
     * @param  array<string, mixed>|null  $adContentReport
     * @param  array<string, int>|null  $utmContentCounts
     * @return array{
     *     posts: list<array<string, mixed>>,
     *     paginator: LengthAwarePaginator,
     *     sort: string
     * }
     */
    public function build(
        SocialEngagementTarget $target,
        ?array $adContentReport,
        ?CarbonImmutable $adContentExpiresAt,
        SocialPostGoogleAnalyticsAttributionReport $attributionReport,
        ?string $sort = null,
        ?array $utmContentCounts = null,
    ): array {
        $sort = in_array($sort, ['published_at', 'name'], true) ? $sort : 'published_at';
        $query = Monitor::query()
            ->where('social_platform', $target->social_platform)
            ->where(static function (Builder $query) use ($target): void {
                $query->whereJsonContains('tags', $target->tag)
                    ->orWhere('label', $target->tag);
            })
            ->select(['id', 'name', 'url', 'social_platform', 'config_version', 'utm_content'])
            ->with(['latestPublicEngagementCheckRun', 'latestCheckRun']);

        if ($target->starts_on !== null && $target->ends_on !== null) {
            $campaignStart = CarbonImmutable::parse(
                $target->starts_on->toDateString().' 00:00:00',
                self::HISTORY_TIMEZONE,
            )->utc();
            $campaignEnd = CarbonImmutable::parse(
                $target->ends_on->toDateString().' 23:59:59.999999',
                self::HISTORY_TIMEZONE,
            )->utc();

            $query->whereHas('latestPublicEngagementCheckRun', static function (Builder $query) use (
                $campaignStart,
                $campaignEnd,
            ): void {
                $query->whereColumn('check_runs.config_version', 'monitors.config_version')
                    ->whereBetween('check_runs.response_published_at', [$campaignStart, $campaignEnd]);
            });
        }

        if ($sort === 'published_at') {
            $latestPublishedAt = CheckRun::query()
                ->select('response_published_at')
                ->whereColumn('check_runs.monitor_id', 'monitors.id')
                ->whereColumn('check_runs.config_version', 'monitors.config_version')
                ->where('check_runs.status', 'completed')
                ->whereNotNull('check_runs.finished_at')
                ->whereNotNull('check_runs.response_public_engagement_metrics')
                ->whereNotIn('check_runs.source', ['tls_daily', 'domain_daily'])
                ->orderByDesc('check_runs.finished_at')
                ->orderByDesc('check_runs.id')
                ->limit(1);

            $query->orderBy($latestPublishedAt, 'desc');
        } else {
            $query->orderBy('name');
        }

        $paginator = $query
            ->orderBy('monitors.id')
            ->paginate(self::PAGE_SIZE)
            ->withQueryString();
        $utmContentCounts ??= $attributionReport->utmContentCountsForTarget($target);
        $posts = [];

        foreach ($paginator->items() as $monitor) {
            if (! $monitor instanceof Monitor) {
                continue;
            }

            $engagementRun = $monitor->latestPublicEngagementCheckRun;

            if ($engagementRun?->config_version !== $monitor->config_version) {
                $engagementRun = null;
            }

            $publishedAt = $engagementRun?->response_published_at
                ?->timezone(self::HISTORY_TIMEZONE);
            $metrics = $engagementRun?->response_public_engagement_metrics ?? [];
            $metricSources = is_array($metrics['metric_sources'] ?? null) ? $metrics['metric_sources'] : [];
            $interactionTotal = Monitor::publicEngagementInteractionTotal($metrics, $target->social_platform);
            $latestCheckRun = $monitor->latestCheckRun;
            $captureIsCurrent = $engagementRun !== null && $latestCheckRun?->id === $engagementRun->id;

            $posts[] = [
                'id' => (int) $monitor->id,
                'name' => $monitor->name,
                'url' => $monitor->url,
                'published_at' => $publishedAt?->toImmutable(),
                'views' => is_numeric($metrics['views'] ?? null) ? (int) $metrics['views'] : null,
                'likes' => is_numeric($metrics['likes'] ?? null) ? (int) $metrics['likes'] : null,
                'comments' => is_numeric($metrics['comments'] ?? null) ? (int) $metrics['comments'] : null,
                'shares' => is_numeric($metrics['shares'] ?? null) ? (int) $metrics['shares'] : null,
                'interaction_total' => $interactionTotal,
                'metrics_freshness' => $interactionTotal === null
                    ? '尚無完整讀值'
                    : ($captureIsCurrent ? '最新檢查讀取' : '沿用前次讀值'),
                'finished_at' => $engagementRun?->finished_at?->toImmutable(),
                'metric_sources' => [
                    'views' => is_string($metricSources['views'] ?? null) ? $metricSources['views'] : null,
                    'likes' => is_string($metricSources['likes'] ?? null) ? $metricSources['likes'] : null,
                    'comments' => is_string($metricSources['comments'] ?? null) ? $metricSources['comments'] : null,
                    'shares' => is_string($metricSources['shares'] ?? null) ? $metricSources['shares'] : null,
                ],
                'utm_content' => $monitor->utm_content,
            ];
        }

        return [
            'posts' => $attributionReport->attachToPosts(
                $target,
                $posts,
                $adContentReport,
                $adContentExpiresAt,
                $utmContentCounts,
            ),
            'paginator' => $paginator,
            'sort' => $sort,
        ];
    }
}
