<?php

namespace App\Services;

use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class SocialEngagementCampaignReport
{
    private const HISTORY_TIMEZONE = 'Asia/Taipei';

    private const PLATFORM_LABELS = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'threads' => 'Threads',
        'dcard' => 'Dcard',
    ];

    /**
     * @return array{
     *     target: SocialEngagementTarget,
     *     report: array{
     *         platform_label: string,
     *         interaction_scope_label: string,
     *         generated_at: CarbonImmutable,
     *         tracked_post_count: int,
     *         complete_read_count: int,
     *         capture_coverage_percent: float|null,
     *         unknown_publication_date_count: int,
     *         interaction_total: int|null,
     *         target_completion_percent: float|null,
     *         average_interactions_per_post: float|null,
     *         engagement_rate_percent: float|null,
     *         metrics: array<string, array{total: int|null, available: int, tracked: int}>,
     *         top_posts: list<array{
     *             id: int,
     *             name: string,
     *             published_at: CarbonImmutable|null,
     *             views: int|null,
     *             likes: int|null,
     *             comments: int|null,
     *             shares: int|null,
     *             interaction_total: int|null,
     *             metrics_freshness: string,
     *             finished_at: CarbonImmutable|null
     *         }>
     *     }
     * }
     */
    public function build(SocialEngagementTarget $target): array
    {
        $platform = $target->social_platform;
        $campaignStart = $target->starts_on?->toDateString();
        $campaignEnd = $target->ends_on?->toDateString();
        $hasCampaignPeriod = $campaignStart !== null && $campaignEnd !== null;
        $trackedPostCount = 0;
        $completeReadCount = 0;
        $unknownPublicationDateCount = 0;
        $interactionTotal = 0;
        $engagementRateInteractions = 0;
        $engagementRateViews = 0;
        $engagementRatePostCount = 0;
        $metricTotals = array_fill_keys(['views', 'likes', 'comments', 'shares'], ['total' => 0, 'available' => 0]);
        $posts = [];
        $sortPosts = static function (array $first, array $second): int {
            if ($first['interaction_total'] === null && $second['interaction_total'] !== null) {
                return 1;
            }

            if ($first['interaction_total'] !== null && $second['interaction_total'] === null) {
                return -1;
            }

            $interactionComparison = ($second['interaction_total'] ?? 0) <=> ($first['interaction_total'] ?? 0);

            return $interactionComparison !== 0
                ? $interactionComparison
                : (strnatcasecmp($first['name'], $second['name']) ?: ($first['id'] <=> $second['id']));
        };

        Monitor::query()
            ->where('social_platform', $platform)
            ->where(function (Builder $query) use ($target): void {
                $query->whereJsonContains('tags', $target->tag)
                    ->orWhere('label', $target->tag);
            })
            ->select(['id', 'name', 'social_platform', 'config_version'])
            ->with(['latestCheckRun', 'latestPublicEngagementCheckRun'])
            ->chunkById(250, function (EloquentCollection $monitorBatch) use (
                &$trackedPostCount,
                &$completeReadCount,
                &$unknownPublicationDateCount,
                &$interactionTotal,
                &$engagementRateInteractions,
                &$engagementRateViews,
                &$engagementRatePostCount,
                &$metricTotals,
                &$posts,
                $platform,
                $campaignStart,
                $campaignEnd,
                $hasCampaignPeriod,
                $sortPosts,
            ): void {
                foreach ($monitorBatch as $monitor) {
                    $engagementRun = $monitor->latestPublicEngagementCheckRun;

                    if ($engagementRun?->config_version !== $monitor->config_version) {
                        $engagementRun = null;
                    }

                    $publishedAt = $engagementRun?->response_published_at?->timezone(self::HISTORY_TIMEZONE);
                    $publishedDate = $publishedAt?->toDateString();

                    if ($hasCampaignPeriod && $publishedDate === null) {
                        $unknownPublicationDateCount++;

                        continue;
                    }

                    if ($hasCampaignPeriod
                        && (($campaignStart !== null && $publishedDate < $campaignStart)
                            || ($campaignEnd !== null && $publishedDate > $campaignEnd))) {
                        continue;
                    }

                    $trackedPostCount++;
                    $metrics = $engagementRun?->response_public_engagement_metrics ?? [];
                    $postInteractionTotal = Monitor::publicEngagementInteractionTotal($metrics, $platform);
                    $views = is_numeric($metrics['views'] ?? null) ? (int) $metrics['views'] : null;

                    foreach (array_keys($metricTotals) as $metric) {
                        if (is_numeric($metrics[$metric] ?? null)) {
                            $metricTotals[$metric]['total'] += (int) $metrics[$metric];
                            $metricTotals[$metric]['available']++;
                        }
                    }

                    if ($postInteractionTotal !== null) {
                        $completeReadCount++;
                        $interactionTotal += $postInteractionTotal;

                        if ($views !== null && $views > 0) {
                            $engagementRateInteractions += $postInteractionTotal;
                            $engagementRateViews += $views;
                            $engagementRatePostCount++;
                        }
                    }

                    $latestCheckRun = $monitor->latestCheckRun;
                    $captureIsCurrent = $engagementRun !== null && $latestCheckRun?->id === $engagementRun->id;

                    $posts[] = [
                        'id' => (int) $monitor->id,
                        'name' => $monitor->name,
                        'published_at' => $publishedAt?->toImmutable(),
                        'views' => $views,
                        'likes' => is_numeric($metrics['likes'] ?? null) ? (int) $metrics['likes'] : null,
                        'comments' => is_numeric($metrics['comments'] ?? null) ? (int) $metrics['comments'] : null,
                        'shares' => is_numeric($metrics['shares'] ?? null) ? (int) $metrics['shares'] : null,
                        'interaction_total' => $postInteractionTotal,
                        'metrics_freshness' => $postInteractionTotal === null
                            ? '尚無完整讀值'
                            : ($captureIsCurrent ? '最新檢查讀取' : '沿用前次讀值'),
                        'finished_at' => $engagementRun?->finished_at?->toImmutable(),
                    ];

                    if (count($posts) > 10) {
                        usort($posts, $sortPosts);
                        array_pop($posts);
                    }
                }
            });

        usort($posts, $sortPosts);

        foreach ($metricTotals as $metric => $summary) {
            $metricTotals[$metric] = [
                'total' => $summary['available'] > 0 ? $summary['total'] : null,
                'available' => $summary['available'],
                'tracked' => $trackedPostCount,
            ];
        }

        return [
            'target' => $target,
            'report' => [
                'platform_label' => self::PLATFORM_LABELS[$platform],
                'interaction_scope_label' => Monitor::publicEngagementInteractionScopeLabel($platform),
                'generated_at' => CarbonImmutable::now(self::HISTORY_TIMEZONE),
                'tracked_post_count' => $trackedPostCount,
                'complete_read_count' => $completeReadCount,
                'capture_coverage_percent' => $trackedPostCount > 0
                    ? round($completeReadCount / $trackedPostCount * 100, 1)
                    : null,
                'unknown_publication_date_count' => $unknownPublicationDateCount,
                'interaction_total' => $completeReadCount > 0 ? $interactionTotal : null,
                'target_completion_percent' => $completeReadCount > 0
                    ? round($interactionTotal / (int) $target->target_interactions * 100, 1)
                    : null,
                'average_interactions_per_post' => $completeReadCount > 0
                    ? round($interactionTotal / $completeReadCount, 1)
                    : null,
                'engagement_rate_percent' => $engagementRatePostCount > 0
                    ? round($engagementRateInteractions / $engagementRateViews * 100, 2)
                    : null,
                'metrics' => $metricTotals,
                'top_posts' => $posts,
            ],
        ];
    }
}
