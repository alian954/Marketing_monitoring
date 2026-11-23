<?php

namespace App\Services;

use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SocialEngagementCampaignAttributionCoverageReport
{
    private const HISTORY_TIMEZONE = 'Asia/Taipei';

    /**
     * @param  array<string, mixed>|null  $adContentReport
     * @return array{
     *     tracked_post_count: int,
     *     utm_configured_post_count: int,
     *     utm_missing_post_count: int,
     *     utm_coverage_percent: float|null,
     *     ga4_unique_utm_post_count: int,
     *     ga4_matched_post_count: int,
     *     ga4_no_match_post_count: int,
     *     ga4_duplicate_utm_post_count: int,
     *     ga4_unresolved_post_count: int,
     *     ga4_match_coverage_percent: float|null,
     *     ga4_report_status: string,
     *     ga4_report_status_label: string,
     *     status_counts: array<string, int>
     * }
     */
    public function build(
        SocialEngagementTarget $target,
        ?array $adContentReport,
        ?CarbonImmutable $adContentExpiresAt,
        SocialPostGoogleAnalyticsAttributionReport $attributionReport,
    ): array {
        $statusCounts = array_fill_keys([
            'not_configured',
            'duplicate_utm_content',
            'thresholded',
            'report_unavailable',
            'report_expired',
            'ambiguous_ga4_rows',
            'ga4_report_truncated',
            'no_ga4_match',
            'matched',
        ], 0);
        $utmContentCounts = $attributionReport->utmContentCountsForTarget($target);
        $campaignStart = $target->starts_on?->toDateString();
        $campaignEnd = $target->ends_on?->toDateString();
        $hasCampaignPeriod = $campaignStart !== null && $campaignEnd !== null;
        $trackedPostCount = 0;

        Monitor::query()
            ->where('social_platform', $target->social_platform)
            ->where(static function (Builder $query) use ($target): void {
                $query->whereJsonContains('tags', $target->tag)
                    ->orWhere('label', $target->tag);
            })
            ->select(['id', 'config_version', 'utm_content'])
            ->with('latestPublicEngagementCheckRun')
            ->chunkById(250, function (Collection $monitors) use (
                $target,
                $adContentReport,
                $adContentExpiresAt,
                $attributionReport,
                $utmContentCounts,
                $campaignStart,
                $campaignEnd,
                $hasCampaignPeriod,
                &$trackedPostCount,
                &$statusCounts,
            ): void {
                $posts = [];

                foreach ($monitors as $monitor) {
                    $engagementRun = $monitor->latestPublicEngagementCheckRun;

                    if ($engagementRun?->config_version !== $monitor->config_version) {
                        $engagementRun = null;
                    }

                    $publishedDate = $engagementRun?->response_published_at
                        ?->timezone(self::HISTORY_TIMEZONE)
                        ->toDateString();

                    if ($hasCampaignPeriod && $publishedDate === null) {
                        continue;
                    }

                    if ($hasCampaignPeriod
                        && (($campaignStart !== null && $publishedDate < $campaignStart)
                            || ($campaignEnd !== null && $publishedDate > $campaignEnd))) {
                        continue;
                    }

                    $posts[] = [
                        'id' => (int) $monitor->id,
                        'utm_content' => $monitor->utm_content,
                    ];
                }

                $attributedPosts = $attributionReport->attachToPosts(
                    $target,
                    $posts,
                    $adContentReport,
                    $adContentExpiresAt,
                    $utmContentCounts,
                );

                foreach ($attributedPosts as $post) {
                    $status = $post['google_analytics_attribution']['status'];
                    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
                    $trackedPostCount++;
                }
            });

        $utmMissingPostCount = $statusCounts['not_configured'];
        $utmConfiguredPostCount = $trackedPostCount - $utmMissingPostCount;
        $duplicateUtmPostCount = $statusCounts['duplicate_utm_content'];
        $uniqueUtmPostCount = max(0, $utmConfiguredPostCount - $duplicateUtmPostCount);
        $matchedPostCount = $statusCounts['matched'];
        $unresolvedPostCount = array_sum(array_intersect_key($statusCounts, array_fill_keys([
            'thresholded',
            'report_unavailable',
            'report_expired',
            'ambiguous_ga4_rows',
            'ga4_report_truncated',
        ], true)));
        $ga4ReportStatus = $this->reportStatus($adContentReport, $adContentExpiresAt);

        return [
            'tracked_post_count' => $trackedPostCount,
            'utm_configured_post_count' => $utmConfiguredPostCount,
            'utm_missing_post_count' => $utmMissingPostCount,
            'utm_coverage_percent' => $trackedPostCount > 0
                ? round($utmConfiguredPostCount / $trackedPostCount * 100, 1)
                : null,
            'ga4_unique_utm_post_count' => $uniqueUtmPostCount,
            'ga4_matched_post_count' => $matchedPostCount,
            'ga4_no_match_post_count' => $statusCounts['no_ga4_match'],
            'ga4_duplicate_utm_post_count' => $duplicateUtmPostCount,
            'ga4_unresolved_post_count' => $unresolvedPostCount,
            'ga4_match_coverage_percent' => $ga4ReportStatus === 'available' && $uniqueUtmPostCount > 0
                ? round($matchedPostCount / $uniqueUtmPostCount * 100, 1)
                : null,
            'ga4_report_status' => $ga4ReportStatus,
            'ga4_report_status_label' => $this->reportStatusLabel($ga4ReportStatus),
            'status_counts' => $statusCounts,
        ];
    }

    /** @param array<string, mixed>|null $adContentReport */
    private function reportStatus(?array $adContentReport, ?CarbonImmutable $adContentExpiresAt): string
    {
        if ($adContentReport === null) {
            return 'unavailable';
        }

        if (($adContentReport['status'] ?? null) === 'thresholded') {
            return 'thresholded';
        }

        if (($adContentReport['status'] ?? null) !== 'complete') {
            return 'unavailable';
        }

        if ($adContentExpiresAt?->isFuture() !== true) {
            return 'expired';
        }

        return ($adContentReport['truncated'] ?? false) === true ? 'truncated' : 'available';
    }

    private function reportStatusLabel(string $status): string
    {
        return match ($status) {
            'available' => 'GA4 明細可核對',
            'truncated' => 'GA4 明細遭截斷，配對率不完整',
            'thresholded' => 'GA4 隱私門檻未提供明細',
            'expired' => 'GA4 素材報表已過期',
            default => '尚無可用 GA4 素材報表',
        };
    }
}
