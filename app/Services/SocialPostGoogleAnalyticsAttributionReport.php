<?php

namespace App\Services;

use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SocialPostGoogleAnalyticsAttributionReport
{
    /**
     * @return array<string, int>
     */
    public function utmContentCountsForTarget(SocialEngagementTarget $target): array
    {
        $utmContentCounts = [];

        Monitor::query()
            ->where('social_platform', $target->social_platform)
            ->where(static function (Builder $query) use ($target): void {
                $query->whereJsonContains('tags', $target->tag)
                    ->orWhere('label', $target->tag);
            })
            ->whereNotNull('utm_content')
            ->select(['id', 'utm_content'])
            ->chunkById(250, function (Collection $monitors) use (&$utmContentCounts): void {
                foreach ($monitors as $monitor) {
                    $utmContent = $monitor->utm_content;

                    if (! is_string($utmContent) || $utmContent === '') {
                        continue;
                    }

                    $utmKey = base64_encode($utmContent);
                    $utmContentCounts[$utmKey] = ($utmContentCounts[$utmKey] ?? 0) + 1;
                }
            });

        return $utmContentCounts;
    }

    /**
     * @param  list<array<string, mixed>>  $posts
     * @param  array<string, mixed>|null  $adContentReport
     * @param  array<string, int>|null  $utmContentCounts
     * @return list<array<string, mixed>>
     */
    public function attachToPosts(
        SocialEngagementTarget $target,
        array $posts,
        ?array $adContentReport,
        ?CarbonImmutable $adContentExpiresAt,
        ?array $utmContentCounts = null,
    ): array {
        if ($posts === []) {
            return [];
        }

        $topPostIds = array_fill_keys(array_map(static fn (array $post): int => (int) $post['id'], $posts), true);
        $topPostUtmContent = [];

        if ($utmContentCounts === null) {
            $utmContentCounts = [];

            Monitor::query()
                ->where('social_platform', $target->social_platform)
                ->where(static function (Builder $query) use ($target): void {
                    $query->whereJsonContains('tags', $target->tag)
                        ->orWhere('label', $target->tag);
                })
                ->whereNotNull('utm_content')
                ->select(['id', 'utm_content'])
                ->chunkById(250, function (Collection $monitors) use (&$topPostUtmContent, &$utmContentCounts, $topPostIds): void {
                    foreach ($monitors as $monitor) {
                        $utmContent = $monitor->utm_content;

                        if (! is_string($utmContent) || $utmContent === '') {
                            continue;
                        }

                        $utmKey = base64_encode($utmContent);
                        $utmContentCounts[$utmKey] = ($utmContentCounts[$utmKey] ?? 0) + 1;

                        if (isset($topPostIds[(int) $monitor->id])) {
                            $topPostUtmContent[(int) $monitor->id] = $utmContent;
                        }
                    }
                });
        } else {
            $postIdsWithoutUtmContentField = [];

            foreach ($posts as $post) {
                $postId = (int) $post['id'];

                if (! array_key_exists('utm_content', $post)) {
                    $postIdsWithoutUtmContentField[] = $postId;

                    continue;
                }

                $utmContent = $post['utm_content'] ?? null;

                if (is_string($utmContent) && $utmContent !== '') {
                    $topPostUtmContent[$postId] = $utmContent;
                }
            }

            if ($postIdsWithoutUtmContentField !== []) {
                Monitor::query()
                    ->where('social_platform', $target->social_platform)
                    ->where(static function (Builder $query) use ($target): void {
                        $query->whereJsonContains('tags', $target->tag)
                            ->orWhere('label', $target->tag);
                    })
                    ->whereIn('id', $postIdsWithoutUtmContentField)
                    ->whereNotNull('utm_content')
                    ->get(['id', 'utm_content'])
                    ->each(static function (Monitor $monitor) use (&$topPostUtmContent): void {
                        if (is_string($monitor->utm_content) && $monitor->utm_content !== '') {
                            $topPostUtmContent[(int) $monitor->id] = $monitor->utm_content;
                        }
                    });
            }
        }

        $reportStatus = $adContentReport['status'] ?? null;
        $reportRows = is_array($adContentReport['rows'] ?? null) ? $adContentReport['rows'] : [];
        $reportTruncated = ($adContentReport['truncated'] ?? false) === true;
        $currencyCode = is_string($adContentReport['currency_code'] ?? null) ? $adContentReport['currency_code'] : null;
        $reportIsFresh = $adContentExpiresAt?->isFuture() === true;

        return array_map(function (array $post) use (
            $topPostUtmContent,
            $utmContentCounts,
            $reportStatus,
            $reportRows,
            $reportTruncated,
            $adContentReport,
            $currencyCode,
            $reportIsFresh,
        ): array {
            $postId = (int) $post['id'];
            $utmContent = $topPostUtmContent[$postId] ?? null;
            $attribution = $this->emptyAttribution($utmContent, $currencyCode);

            if ($utmContent === null) {
                $attribution['status'] = 'not_configured';
                $attribution['status_label'] = '未設定素材值';
            } elseif (($utmContentCounts[base64_encode($utmContent)] ?? 0) > 1) {
                $attribution['status'] = 'duplicate_utm_content';
                $attribution['status_label'] = '多篇貼文使用此素材值';
            } elseif ($reportStatus === 'thresholded') {
                $attribution['status'] = 'thresholded';
                $attribution['status_label'] = 'GA4 隱私門檻未提供明細';
            } elseif ($adContentReport === null || $reportStatus !== 'complete') {
                $attribution['status'] = 'report_unavailable';
                $attribution['status_label'] = 'GA4 素材資料不可用';
            } elseif (! $reportIsFresh) {
                $attribution['status'] = 'report_expired';
                $attribution['status_label'] = 'GA4 素材報表已過期';
            } else {
                $matchingRows = array_values(array_filter(
                    $reportRows,
                    static fn (mixed $row): bool => is_array($row)
                        && is_string($row['ad_content'] ?? null)
                        && $row['ad_content'] === $utmContent,
                ));

                if (count($matchingRows) !== 1) {
                    $attribution['status'] = count($matchingRows) > 1
                        ? 'ambiguous_ga4_rows'
                        : ($reportTruncated ? 'ga4_report_truncated' : 'no_ga4_match');
                    $attribution['status_label'] = count($matchingRows) > 1
                        ? 'GA4 素材資料重複'
                        : ($reportTruncated ? 'GA4 素材明細遭截斷，無法確認' : 'GA4 尚無相同素材值');
                } else {
                    $row = $matchingRows[0];
                    $attribution['status'] = 'matched';
                    $attribution['status_label'] = '已依 UTM 對應';
                    $attribution['sessions'] = is_numeric($row['sessions'] ?? null) ? (int) $row['sessions'] : null;
                    $attribution['engaged_sessions'] = is_numeric($row['engaged_sessions'] ?? null) ? (int) $row['engaged_sessions'] : null;
                    $attribution['engagement_rate'] = is_numeric($row['engagement_rate'] ?? null) ? (string) $row['engagement_rate'] : null;
                    $attribution['key_events'] = is_numeric($row['key_events'] ?? null) ? (string) $row['key_events'] : null;
                    $attribution['total_revenue'] = is_numeric($row['total_revenue'] ?? null) ? (string) $row['total_revenue'] : null;
                }
            }

            $post['google_analytics_attribution'] = $attribution;

            return $post;
        }, $posts);
    }

    /**
     * @return array{
     *     utm_content: string|null,
     *     status: string,
     *     status_label: string,
     *     sessions: int|null,
     *     engaged_sessions: int|null,
     *     engagement_rate: string|null,
     *     key_events: string|null,
     *     total_revenue: string|null,
     *     currency_code: string|null
     * }
     */
    private function emptyAttribution(?string $utmContent, ?string $currencyCode): array
    {
        return [
            'utm_content' => $utmContent,
            'status' => 'unavailable',
            'status_label' => 'GA4 素材資料不可用',
            'sessions' => null,
            'engaged_sessions' => null,
            'engagement_rate' => null,
            'key_events' => null,
            'total_revenue' => null,
            'currency_code' => $currencyCode,
        ];
    }
}
