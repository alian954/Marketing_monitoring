<?php

namespace App\Services;

use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SocialEngagementCampaignPostCsv
{
    private const HISTORY_TIMEZONE = 'Asia/Taipei';

    /**
     * @param  resource  $stream
     * @param  array<string, mixed>|null  $adContentReport
     */
    public function write(
        SocialEngagementTarget $target,
        mixed $stream,
        ?array $adContentReport,
        ?CarbonImmutable $adContentExpiresAt,
        SocialPostGoogleAnalyticsAttributionReport $attributionReport,
    ): void {
        fputcsv($stream, $this->headers(), ',', '"', '');

        $utmContentCounts = $attributionReport->utmContentCountsForTarget($target);
        $campaignStart = $target->starts_on?->toDateString();
        $campaignEnd = $target->ends_on?->toDateString();
        $hasCampaignPeriod = $campaignStart !== null && $campaignEnd !== null;

        Monitor::query()
            ->where('social_platform', $target->social_platform)
            ->where(function (Builder $query) use ($target): void {
                $query->whereJsonContains('tags', $target->tag)
                    ->orWhere('label', $target->tag);
            })
            ->select(['id', 'name', 'url', 'social_platform', 'config_version', 'utm_content'])
            ->with(['latestPublicEngagementCheckRun', 'latestCheckRun'])
            ->chunkById(250, function (Collection $monitors) use (
                $target,
                $stream,
                $adContentReport,
                $adContentExpiresAt,
                $attributionReport,
                $utmContentCounts,
                $campaignStart,
                $campaignEnd,
                $hasCampaignPeriod,
            ): void {
                $posts = [];

                foreach ($monitors as $monitor) {
                    $engagementRun = $monitor->latestPublicEngagementCheckRun;

                    if ($engagementRun?->config_version !== $monitor->config_version) {
                        $engagementRun = null;
                    }

                    $publishedAt = $engagementRun?->response_published_at?->timezone(self::HISTORY_TIMEZONE);
                    $publishedDate = $publishedAt?->toDateString();

                    if ($hasCampaignPeriod && $publishedDate === null) {
                        continue;
                    }

                    if ($hasCampaignPeriod
                        && (($campaignStart !== null && $publishedDate < $campaignStart)
                            || ($campaignEnd !== null && $publishedDate > $campaignEnd))) {
                        continue;
                    }

                    $metrics = $engagementRun?->response_public_engagement_metrics ?? [];
                    $metricSources = is_array($metrics['metric_sources'] ?? null) ? $metrics['metric_sources'] : [];
                    $interactionTotal = Monitor::publicEngagementInteractionTotal($metrics, $target->social_platform);
                    $latestCheckRun = $monitor->latestCheckRun;
                    $captureIsCurrent = $engagementRun !== null && $latestCheckRun?->id === $engagementRun->id;

                    $posts[] = [
                        'id' => (int) $monitor->id,
                        'name' => $monitor->name,
                        'url' => $monitor->url,
                        'social_platform' => $monitor->social_platform,
                        'utm_content' => $monitor->utm_content,
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
                    ];
                }

                $posts = $attributionReport->attachToPosts(
                    $target,
                    $posts,
                    $adContentReport,
                    $adContentExpiresAt,
                    $utmContentCounts,
                );

                foreach ($posts as $post) {
                    $this->writePost($stream, $post, $target);
                }
            });
    }

    /** @return list<string> */
    private function headers(): array
    {
        return [
            'monitor_id',
            'name',
            'url',
            'social_platform',
            'published_at_asia_taipei',
            'views',
            'likes',
            'comments',
            'shares',
            'public_interactions',
            'interaction_scope',
            'metric_freshness',
            'metrics_finished_at_asia_taipei',
            'views_source',
            'likes_source',
            'comments_source',
            'shares_source',
            'utm_content',
            'ga4_attribution_status',
            'ga4_attribution_status_label',
            'ga4_sessions',
            'ga4_engaged_sessions',
            'ga4_engagement_rate',
            'ga4_key_events',
            'ga4_total_revenue',
            'ga4_currency_code',
        ];
    }

    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $post
     */
    private function writePost(mixed $stream, array $post, SocialEngagementTarget $target): void
    {
        $attribution = $post['google_analytics_attribution'];
        $metricSources = $post['metric_sources'];
        $row = [
            (string) $post['id'],
            $post['name'],
            $post['url'],
            $post['social_platform'],
            $this->formatDateTime($post['published_at']),
            $this->formatNullableValue($post['views']),
            $this->formatNullableValue($post['likes']),
            $this->formatNullableValue($post['comments']),
            $this->formatNullableValue($post['shares']),
            $this->formatNullableValue($post['interaction_total']),
            Monitor::publicEngagementInteractionScopeLabel($target->social_platform),
            $post['metrics_freshness'],
            $this->formatDateTime($post['finished_at']),
            $this->formatNullableValue($metricSources['views']),
            $this->formatNullableValue($metricSources['likes']),
            $this->formatNullableValue($metricSources['comments']),
            $this->formatNullableValue($metricSources['shares']),
            $this->formatNullableValue($attribution['utm_content']),
            $attribution['status'],
            $attribution['status_label'],
            $this->formatNullableValue($attribution['sessions']),
            $this->formatNullableValue($attribution['engaged_sessions']),
            $this->formatNullableValue($attribution['engagement_rate']),
            $this->formatNullableValue($attribution['key_events']),
            $this->formatNullableValue($attribution['total_revenue']),
            $this->formatNullableValue($attribution['currency_code']),
        ];

        fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $row), ',', '"', '');
    }

    private function formatDateTime(?CarbonImmutable $dateTime): string
    {
        return $dateTime?->timezone(self::HISTORY_TIMEZONE)->format('Y-m-d H:i:s') ?? '';
    }

    private function formatNullableValue(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
