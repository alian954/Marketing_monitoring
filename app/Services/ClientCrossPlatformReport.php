<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientMonthlyBudget;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Assembles everything shown on a client's cross-platform overview for one period, so the page,
 * the budget page and the AI summary all read the same numbers.
 */
class ClientCrossPlatformReport
{
    public function __construct(
        private readonly DashboardCampaignMarketingSummary $dashboardCampaignMarketingSummary,
        private readonly ClientCrossPlatformOverview $crossPlatformOverview,
        private readonly ClientMarketingTimeline $marketingTimeline,
        private readonly ClientBudgetPacing $budgetPacing,
        private readonly ClientStoreRevenueSummary $storeRevenueSummary,
        private readonly ClientAdPlatformMetricImporter $adPlatformMetrics,
        private readonly ClientLeadFunnelReport $leadFunnelReport,
    ) {}

    /**
     * @param  array{from: string, to: string}  $period
     * @return array{
     *     period: array{from: string, to: string},
     *     previous_period: array{from: string, to: string},
     *     overview: array<string, mixed>,
     *     comparison: array<string, mixed>,
     *     chart: array<string, mixed>|null,
     *     timeline: list<array{date: string, source: string, category_label: string, title: string, note: string|null}>,
     *     chart_markers: list<array{date: string, x: float, sources: list<string>, label: string}>,
     *     campaign_count: int,
     *     budget_pacing: array<string, mixed>|null,
     *     store_revenue: array<string, mixed>,
     *     lead_funnel: array<string, mixed>
     * }
     */
    public function build(Client $client, User $user, array $period): array
    {
        $now = now()->toImmutable();
        $previousPeriod = $this->previousPeriod($period);
        $targets = $this->targets($client, $previousPeriod['from'], $period['to']);
        $marketingSummaries = $this->dashboardCampaignMarketingSummary->build($targets, $user, $now);
        $importedCampaigns = $this->adPlatformMetrics->campaignsForPeriod($client, $previousPeriod['from'], $period['to']);
        $overview = $this->crossPlatformOverview->build($targets, $marketingSummaries, $period['from'], $period['to'], $importedCampaigns);
        $previousOverview = $this->crossPlatformOverview->build($targets, $marketingSummaries, $previousPeriod['from'], $previousPeriod['to'], $importedCampaigns);
        $chart = $this->crossPlatformOverview->chart($overview['daily']);
        $timeline = $this->marketingTimeline->build($client, $period['from'], $period['to']);
        $budget = $client->monthlyBudgets()->where('month', substr($period['to'], 0, 7))->first();

        return [
            'period' => $period,
            'previous_period' => $previousPeriod,
            'overview' => $overview,
            'comparison' => $this->crossPlatformOverview->compare($overview, $previousOverview),
            'chart' => $chart,
            'timeline' => $timeline,
            'chart_markers' => $chart === null ? [] : $this->marketingTimeline->chartMarkers($timeline, $chart['bars']),
            'campaign_count' => $targets->filter(
                static fn (SocialEngagementTarget $target): bool => $target->starts_on !== null
                    && $target->ends_on !== null
                    && $target->starts_on->toDateString() <= $period['to']
                    && $target->ends_on->toDateString() >= $period['from'],
            )->count(),
            'budget_pacing' => $budget === null ? null : $this->budgetPacing($budget, $client, $user, $now),
            'store_revenue' => $this->storeRevenueSummary->build($client, $period['from'], $period['to'], $overview),
            'lead_funnel' => $this->leadFunnelReport->build($client, $period['from'], $period['to'], $overview),
        ];
    }

    /** @return array<string, mixed> */
    public function budgetPacing(ClientMonthlyBudget $budget, Client $client, User $user, CarbonImmutable $now): array
    {
        $period = $this->budgetPacing->dataPeriod($budget->month, $now);
        $overview = null;

        if ($period['has_elapsed_days']) {
            $targets = $this->targets($client, $period['from'], $period['to']);
            $summaries = $this->dashboardCampaignMarketingSummary->build($targets, $user, $now);
            $overview = $this->crossPlatformOverview->build(
                $targets,
                $summaries,
                $period['from'],
                $period['to'],
                $this->adPlatformMetrics->campaignsForPeriod($client, $period['from'], $period['to']),
            );
        }

        return $this->budgetPacing->pace($budget, $overview, $now);
    }

    /**
     * @param  array{from: string, to: string}  $period
     * @return array{from: string, to: string}
     */
    public function previousPeriod(array $period): array
    {
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $period['from'], 'Asia/Taipei');
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $period['to'], 'Asia/Taipei');
        $days = (int) $from->diff($to)->days + 1;
        $previousTo = $from->subDay();

        return [
            'from' => $previousTo->subDays($days - 1)->toDateString(),
            'to' => $previousTo->toDateString(),
        ];
    }

    /** @return Collection<int, SocialEngagementTarget> */
    private function targets(Client $client, string $fromOn, string $toOn): Collection
    {
        return SocialEngagementTarget::query()
            ->where('client_id', $client->getKey())
            ->whereDate('starts_on', '<=', $toOn)
            ->whereDate('ends_on', '>=', $fromOn)
            ->orderBy('id')
            ->get();
    }
}
