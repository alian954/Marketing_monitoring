<?php

namespace App\Services;

use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SocialEngagementCampaignCreativePerformanceReport
{
    private const HISTORY_TIMEZONE = 'Asia/Taipei';

    /**
     * @param  array<string, mixed>|null  $adContentReport
     * @return array{
     *     groups: list<array<string, mixed>>,
     *     utm_content_counts: array<string, int>,
     *     sort: 'interactions'|'revenue_per_interaction'
     * }
     */
    public function build(
        SocialEngagementTarget $target,
        ?array $adContentReport,
        ?CarbonImmutable $adContentExpiresAt,
        SocialPostGoogleAnalyticsAttributionReport $attributionReport,
        string $sort = 'interactions',
    ): array {
        $sort = in_array($sort, ['interactions', 'revenue_per_interaction'], true)
            ? $sort
            : 'interactions';
        $campaignStart = $target->starts_on?->toDateString();
        $campaignEnd = $target->ends_on?->toDateString();
        $hasCampaignPeriod = $campaignStart !== null && $campaignEnd !== null;
        $groupedCreatives = [];
        $utmContentCounts = [];

        Monitor::query()
            ->where('social_platform', $target->social_platform)
            ->where(static function (Builder $query) use ($target): void {
                $query->whereJsonContains('tags', $target->tag)
                    ->orWhere('label', $target->tag);
            })
            ->select(['id', 'config_version', 'social_platform', 'utm_content'])
            ->with('latestPublicEngagementCheckRun')
            ->chunkById(250, function (Collection $monitors) use (
                &$groupedCreatives,
                &$utmContentCounts,
                $target,
                $campaignStart,
                $campaignEnd,
                $hasCampaignPeriod,
            ): void {
                foreach ($monitors as $monitor) {
                    $utmContent = is_string($monitor->utm_content) && $monitor->utm_content !== ''
                        ? $monitor->utm_content
                        : null;

                    if ($utmContent !== null) {
                        $utmKey = base64_encode($utmContent);
                        $utmContentCounts[$utmKey] = ($utmContentCounts[$utmKey] ?? 0) + 1;
                    }

                    $engagementRun = $monitor->latestPublicEngagementCheckRun;

                    if ($engagementRun?->config_version !== $monitor->config_version) {
                        $engagementRun = null;
                    }

                    $publishedAt = $engagementRun?->response_published_at
                        ?->timezone(self::HISTORY_TIMEZONE);
                    $publishedDate = $publishedAt?->toDateString();

                    if ($hasCampaignPeriod && $publishedDate === null) {
                        continue;
                    }

                    if ($hasCampaignPeriod
                        && (($campaignStart !== null && $publishedDate < $campaignStart)
                            || ($campaignEnd !== null && $publishedDate > $campaignEnd))) {
                        continue;
                    }

                    $groupKey = $utmContent === null ? 'unconfigured' : base64_encode($utmContent);

                    if (! isset($groupedCreatives[$groupKey])) {
                        $groupedCreatives[$groupKey] = [
                            'id' => count($groupedCreatives) + 1,
                            'utm_content' => $utmContent,
                            'post_count' => 0,
                            'complete_read_count' => 0,
                            'public_interaction_total' => null,
                            'average_public_interactions' => null,
                            'latest_capture_at' => null,
                        ];
                    }

                    $group = &$groupedCreatives[$groupKey];
                    $group['post_count']++;

                    $metrics = $engagementRun?->response_public_engagement_metrics ?? [];
                    $interactionTotal = Monitor::publicEngagementInteractionTotal($metrics, $target->social_platform);

                    if ($interactionTotal !== null) {
                        $group['complete_read_count']++;
                        $group['public_interaction_total'] = ($group['public_interaction_total'] ?? 0) + $interactionTotal;
                    }

                    if ($engagementRun?->finished_at !== null
                        && ($group['latest_capture_at'] === null || $engagementRun->finished_at->greaterThan($group['latest_capture_at']))) {
                        $group['latest_capture_at'] = $engagementRun->finished_at->toImmutable();
                    }

                    unset($group);
                }
            });

        $groups = array_values($groupedCreatives);
        $postsForAttribution = [];

        foreach ($groups as $group) {
            $postsForAttribution[] = [
                'id' => $group['id'],
                'utm_content' => $group['utm_content'],
            ];
        }

        $groupsWithAttribution = $attributionReport->attachToPosts(
            $target,
            $postsForAttribution,
            $adContentReport,
            $adContentExpiresAt,
            [],
        );

        foreach ($groups as $index => $group) {
            $completeReadCount = $group['complete_read_count'];
            $group['average_public_interactions'] = $completeReadCount > 0
                ? round($group['public_interaction_total'] / $completeReadCount, 2)
                : null;
            $group['google_analytics_attribution'] = $groupsWithAttribution[$index]['google_analytics_attribution'];
            $group = $this->addRevenuePerPublicInteraction($group);
            $groups[$index] = $group;
        }

        usort($groups, fn (array $first, array $second): int => $this->compareGroups($first, $second, $sort));

        return [
            'groups' => $groups,
            'utm_content_counts' => $utmContentCounts,
            'sort' => $sort,
        ];
    }

    /** @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function addRevenuePerPublicInteraction(array $group): array
    {
        $attribution = $group['google_analytics_attribution'];
        $currencyCode = is_string($attribution['currency_code'] ?? null)
            ? strtoupper(trim($attribution['currency_code']))
            : '';
        $revenue = $attribution['total_revenue'] ?? null;
        $status = 'available';
        $statusLabel = '參考值';
        $value = null;

        if (($attribution['status'] ?? null) !== 'matched') {
            $status = 'ga4_unavailable';
            $statusLabel = 'GA4 對應不足';
        } elseif (preg_match('/^[A-Z]{3}$/D', $currencyCode) !== 1) {
            $status = 'currency_unknown';
            $statusLabel = 'GA4 貨幣未知';
        } elseif (! is_numeric($revenue) || ! is_finite((float) $revenue)) {
            $status = 'revenue_unavailable';
            $statusLabel = 'GA4 收益不可用';
        } elseif ($group['complete_read_count'] !== $group['post_count']) {
            $status = 'incomplete_public_reads';
            $statusLabel = '公開互動讀值未完整';
        } elseif (! is_numeric($group['public_interaction_total']) || (int) $group['public_interaction_total'] < 1) {
            $status = 'no_public_interactions';
            $statusLabel = '沒有公開互動';
        } else {
            $value = (float) $revenue / (int) $group['public_interaction_total'];

            if (! is_finite($value)) {
                $status = 'revenue_unavailable';
                $statusLabel = 'GA4 收益不可用';
                $value = null;
            }
        }

        $group['revenue_per_public_interaction'] = $value;
        $group['revenue_per_public_interaction_currency'] = $status === 'available' ? $currencyCode : null;
        $group['revenue_per_public_interaction_status'] = $status;
        $group['revenue_per_public_interaction_status_label'] = $statusLabel;

        return $group;
    }

    /** @param array<string, mixed> $first
     * @param  array<string, mixed>  $second
     */
    private function compareGroups(array $first, array $second, string $sort): int
    {
        if ($sort === 'revenue_per_interaction') {
            $firstValue = $first['revenue_per_public_interaction'];
            $secondValue = $second['revenue_per_public_interaction'];

            if ($firstValue === null && $secondValue !== null) {
                return 1;
            }

            if ($firstValue !== null && $secondValue === null) {
                return -1;
            }

            $valueComparison = ($secondValue ?? 0) <=> ($firstValue ?? 0);

            if ($valueComparison !== 0) {
                return $valueComparison;
            }
        }

        if ($first['public_interaction_total'] === null && $second['public_interaction_total'] !== null) {
            return 1;
        }

        if ($first['public_interaction_total'] !== null && $second['public_interaction_total'] === null) {
            return -1;
        }

        $interactionComparison = ($second['public_interaction_total'] ?? 0) <=> ($first['public_interaction_total'] ?? 0);

        return $interactionComparison !== 0
            ? $interactionComparison
            : strnatcasecmp((string) $first['utm_content'], (string) $second['utm_content']);
    }
}
