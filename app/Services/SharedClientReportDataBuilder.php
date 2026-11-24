<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientReportShare;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class SharedClientReportDataBuilder
{
    public function __construct(
        private DashboardCampaignMarketingSummary $marketingSummaryBuilder,
        private CampaignPortfolioMarketingSummary $portfolioSummaryBuilder,
        private ClientPortfolioMetricComparison $metricComparison,
        private ClientPortfolioReportingPeriod $reportingPeriod,
        private CampaignEstimatedProfitabilityReport $estimatedProfitabilityReport,
    ) {}

    /**
     * @return array{
     *     share: ClientReportShare,
     *     client: Client,
     *     campaignRows: Collection<int, array{target: SocialEngagementTarget, public_engagement: array<string, mixed>, marketing: array<string, mixed>, estimated_profitability: array<string, mixed>, comparison: array<string, mixed>|null}>,
     *     reportingCurrencyGroups: list<array<string, mixed>>,
     *     reportSections: list<string>,
     *     reportPeriod: array{from: string, to: string, label: string}|null,
     *     comparisonPeriod: array{from: string, to: string, label: string}|null
     * }
     */
    public function buildForToken(string $token): array
    {
        return $this->buildForShare($this->activeShareForToken($token));
    }

    public function activeShareForToken(string $token): ClientReportShare
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $token) === 1, 404);

        return ClientReportShare::query()
            ->with(['client.user'])
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();
    }

    /**
     * @return array{
     *     share: ClientReportShare,
     *     client: Client,
     *     campaignRows: Collection<int, array{target: SocialEngagementTarget, public_engagement: array<string, mixed>, marketing: array<string, mixed>, estimated_profitability: array<string, mixed>, comparison: array<string, mixed>|null}>,
     *     reportingCurrencyGroups: list<array<string, mixed>>,
     *     reportSections: list<string>,
     *     reportPeriod: array{from: string, to: string, label: string}|null,
     *     comparisonPeriod: array{from: string, to: string, label: string}|null
     * }
     */
    public function buildForShare(ClientReportShare $share): array
    {
        $share->loadMissing(['client.user']);
        $client = $share->client;
        $user = $client->user;
        abort_unless($user instanceof User, 404);

        $targetQuery = $client->socialEngagementTargets();

        if (is_array($share->campaign_target_ids)) {
            $targetQuery->whereIn('id', $share->campaign_target_ids);
        }

        $targets = $targetQuery
            ->with('client:id,reporting_currency_code')
            ->orderBy('tag')
            ->orderBy('social_platform')
            ->get();
        $reportSections = $share->selectedReportSections();
        $reportPeriod = $share->report_starts_on !== null && $share->report_ends_on !== null
            ? [
                'from' => $share->report_starts_on->toDateString(),
                'to' => $share->report_ends_on->toDateString(),
                'label' => $share->report_starts_on->format('Y/n/j').'–'.$share->report_ends_on->format('Y/n/j'),
            ]
            : null;
        $monitors = $client->monitors()
            ->whereNotNull('social_platform')
            ->with(['latestPublicEngagementCheckRun'])
            ->get();
        $publicEngagementSummaries = $this->publicEngagementSummaries(
            $targets,
            $monitors,
            $reportPeriod['from'] ?? null,
            $reportPeriod['to'] ?? null,
        );
        $marketingSummaries = $this->marketingSummaryBuilder->build(
            $targets,
            $user,
            CarbonImmutable::now(),
            $publicEngagementSummaries,
        );
        $comparisonPeriod = $share->compare_previous_period && $reportPeriod !== null
            ? $this->previousReportingPeriod($reportPeriod)
            : null;
        $allTargets = $targets;
        $allMarketingSummaries = $marketingSummaries;
        $comparisonPeriodData = $comparisonPeriod === null
            ? null
            : $this->reportingPeriod->apply(
                $allTargets,
                $allMarketingSummaries,
                $comparisonPeriod['from'],
                $comparisonPeriod['to'],
            );

        if ($reportPeriod !== null) {
            $periodData = $this->reportingPeriod->apply(
                $targets,
                $marketingSummaries,
                $reportPeriod['from'],
                $reportPeriod['to'],
            );
            $targets = $periodData['targets'];
            $marketingSummaries = $periodData['marketing_summaries'];
        }

        $exchangeRates = $client->exchangeRates()->get();
        $reportingCurrencySummary = $this->portfolioSummaryBuilder->build(
            $targets,
            $marketingSummaries,
            $exchangeRates,
        );
        $reportingCurrencyGroups = $reportingCurrencySummary['reporting_currency_groups'];

        if ($comparisonPeriod !== null) {
            $comparisonSummary = $this->portfolioSummaryBuilder->build(
                $comparisonPeriodData['targets'],
                $comparisonPeriodData['marketing_summaries'],
                $exchangeRates,
            );
            $previousGroupsByCurrency = collect($comparisonSummary['reporting_currency_groups'])->keyBy('currency_code');
            $reportingCurrencyGroups = collect($reportingCurrencyGroups)
                ->map(function (array $group) use ($previousGroupsByCurrency): array {
                    $previousGroup = $previousGroupsByCurrency->get($group['currency_code']);
                    $group['comparison'] = $this->metricComparison->build(
                        [$group],
                        $previousGroup === null ? [] : [$previousGroup],
                        [],
                        [],
                    )['money'];

                    return $group;
                })
                ->all();
        }

        $comparisonTargetsById = $comparisonPeriodData === null
            ? collect()
            : $comparisonPeriodData['targets']->keyBy(static fn (SocialEngagementTarget $target): int => (int) $target->getKey());
        $comparisonMarketingSummaries = $comparisonPeriodData['marketing_summaries'] ?? [];
        $campaignRows = $targets->map(function (SocialEngagementTarget $target) use (
            $comparisonMarketingSummaries,
            $comparisonPeriod,
            $comparisonTargetsById,
            $exchangeRates,
            $marketingSummaries,
            $publicEngagementSummaries,
            $reportPeriod,
            $reportSections,
        ): array {
            $targetId = (int) $target->getKey();
            $marketing = $marketingSummaries[$targetId];
            $comparison = null;

            if ($comparisonPeriod !== null) {
                $previousTarget = $comparisonTargetsById->get($targetId);
                $previousMarketing = $comparisonMarketingSummaries[$targetId] ?? [];
                $currentCampaignSummary = $this->portfolioSummaryBuilder->build(
                    new EloquentCollection([$target]),
                    [$targetId => $marketing],
                    $exchangeRates,
                );
                $previousCampaignSummary = $previousTarget === null
                    ? null
                    : $this->portfolioSummaryBuilder->build(
                        new EloquentCollection([$previousTarget]),
                        [$targetId => $previousMarketing],
                        $exchangeRates,
                    );
                $currentGa4Roas = $this->ga4RoasComparisonGroup($marketing);
                $previousGa4Roas = $this->ga4RoasComparisonGroup($previousMarketing);
                $comparison = $this->metricComparison->build(
                    $currentCampaignSummary['currency_groups'],
                    $previousCampaignSummary['currency_groups'] ?? [],
                    $currentGa4Roas === null ? [] : [$currentGa4Roas],
                    $previousGa4Roas === null ? [] : [$previousGa4Roas],
                );
            }

            return [
                'target' => $target,
                'public_engagement' => $publicEngagementSummaries[$targetId],
                'marketing' => $marketing,
                'estimated_profitability' => in_array(ClientReportShare::SECTION_ESTIMATED_PROFITABILITY, $reportSections, true)
                    ? $this->estimatedProfitabilityReport->buildForMarketingSummary(
                        $target,
                        $marketing,
                        $reportPeriod === null ? null : ['from' => $reportPeriod['from'], 'to' => $reportPeriod['to']],
                    )
                    : [],
                'comparison' => $comparison,
            ];
        });

        return compact(
            'share',
            'client',
            'campaignRows',
            'reportingCurrencyGroups',
            'reportSections',
            'reportPeriod',
            'comparisonPeriod',
        );
    }

    /**
     * @param  array{from: string, to: string, label: string}  $reportPeriod
     * @return array{from: string, to: string, label: string}
     */
    private function previousReportingPeriod(array $reportPeriod): array
    {
        $currentStart = CarbonImmutable::createFromFormat('!Y-m-d', $reportPeriod['from'], 'Asia/Taipei');
        $currentEnd = CarbonImmutable::createFromFormat('!Y-m-d', $reportPeriod['to'], 'Asia/Taipei');
        $periodLengthDays = (int) $currentStart->diff($currentEnd)->days + 1;
        $previousEnd = $currentStart->subDay();
        $from = $previousEnd->subDays($periodLengthDays - 1)->toDateString();
        $to = $previousEnd->toDateString();

        return [
            'from' => $from,
            'to' => $to,
            'label' => CarbonImmutable::parse($from, 'Asia/Taipei')->format('Y/n/j')
                .'–'.CarbonImmutable::parse($to, 'Asia/Taipei')->format('Y/n/j'),
        ];
    }

    /**
     * @param  array<string, mixed>  $marketing
     * @return array{source: string, source_label: string, currency_code: string, roas: float}|null
     */
    private function ga4RoasComparisonGroup(array $marketing): ?array
    {
        $efficiency = is_array($marketing['efficiency'] ?? null) ? $marketing['efficiency'] : [];
        $roas = $efficiency['roas'] ?? null;
        $currencyCode = $efficiency['roas_spend_currency'] ?? null;

        if (! is_numeric($roas)
            || ! is_finite((float) $roas)
            || ! is_string($currencyCode)
            || trim($currencyCode) === '') {
            return null;
        }

        return [
            'source' => 'ga4_roas',
            'source_label' => 'GA4 ROAS',
            'currency_code' => strtoupper(trim($currencyCode)),
            'roas' => (float) $roas,
        ];
    }

    /**
     * @param  Collection<int, SocialEngagementTarget>  $targets
     * @param  Collection<int, Monitor>  $monitors
     * @return array<int, array{interaction_total: int|null, available_posts: int, tracked_posts: int, views_total: int|null, latest_at: CarbonImmutable|null}>
     */
    private function publicEngagementSummaries(
        Collection $targets,
        Collection $monitors,
        ?string $reportStartsOn = null,
        ?string $reportEndsOn = null,
    ): array {
        $summaries = [];

        foreach ($targets as $target) {
            $startsOn = $target->starts_on?->toDateString();
            $endsOn = $target->ends_on?->toDateString();
            $startsOn = $reportStartsOn === null ? $startsOn : ($startsOn === null ? $reportStartsOn : max($startsOn, $reportStartsOn));
            $endsOn = $reportEndsOn === null ? $endsOn : ($endsOn === null ? $reportEndsOn : min($endsOn, $reportEndsOn));
            $trackedPosts = 0;
            $availablePosts = 0;
            $interactionTotal = 0;
            $viewsTotal = 0;
            $viewCount = 0;
            $latestAt = null;

            foreach ($monitors as $monitor) {
                if ($monitor->social_platform !== $target->social_platform
                    || ! in_array($target->tag, $monitor->tagNames(), true)) {
                    continue;
                }

                $run = $monitor->latestPublicEngagementCheckRun;
                $publishedAt = $run?->response_published_at;

                if (($startsOn !== null || $endsOn !== null)
                    && ($publishedAt === null
                        || ($startsOn !== null && $publishedAt->toDateString() < $startsOn)
                        || ($endsOn !== null && $publishedAt->toDateString() > $endsOn))) {
                    continue;
                }

                $trackedPosts++;

                if ($run === null || $run->config_version !== $monitor->config_version) {
                    continue;
                }

                $metrics = is_array($run->response_public_engagement_metrics)
                    ? $run->response_public_engagement_metrics
                    : [];
                $postInteractions = Monitor::publicEngagementInteractionTotal($metrics, $target->social_platform);

                if ($postInteractions === null) {
                    continue;
                }

                $availablePosts++;
                $interactionTotal += $postInteractions;
                $latestAt = $latestAt === null || $run->finished_at?->greaterThan($latestAt)
                    ? $run->finished_at?->toImmutable()
                    : $latestAt;

                if (is_numeric($metrics['views'] ?? null)) {
                    $viewsTotal += (int) $metrics['views'];
                    $viewCount++;
                }
            }

            $summaries[$target->getKey()] = [
                'interaction_total' => $availablePosts > 0 ? $interactionTotal : null,
                'available_posts' => $availablePosts,
                'tracked_posts' => $trackedPosts,
                'views_total' => $viewCount > 0 ? $viewsTotal : null,
                'latest_at' => $latestAt,
            ];
        }

        return $summaries;
    }
}
