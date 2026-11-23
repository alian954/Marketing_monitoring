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
     *     utm_content_counts: array<string, int>
     * }
     */
    public function build(
        SocialEngagementTarget $target,
        ?array $adContentReport,
        ?CarbonImmutable $adContentExpiresAt,
        SocialPostGoogleAnalyticsAttributionReport $attributionReport,
    ): array {
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
            $groups[$index] = $group;
        }

        usort($groups, static function (array $first, array $second): int {
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
        });

        return [
            'groups' => $groups,
            'utm_content_counts' => $utmContentCounts,
        ];
    }
}
