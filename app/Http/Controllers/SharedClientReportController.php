<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientReportShare;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use App\Services\CampaignPortfolioMarketingSummary;
use App\Services\ClientPortfolioReportingPeriod;
use App\Services\DashboardCampaignMarketingSummary;
use App\Services\SharedClientReportCsv;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SharedClientReportController extends Controller
{
    public function __invoke(
        string $token,
        DashboardCampaignMarketingSummary $marketingSummaryBuilder,
        CampaignPortfolioMarketingSummary $portfolioSummaryBuilder,
        ClientPortfolioReportingPeriod $reportingPeriod,
    ): Response {
        $reportData = $this->reportData($token, $marketingSummaryBuilder, $portfolioSummaryBuilder, $reportingPeriod);

        return response()
            ->view('shared-client-reports.show', $reportData + ['token' => $token])
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function export(
        string $token,
        DashboardCampaignMarketingSummary $marketingSummaryBuilder,
        CampaignPortfolioMarketingSummary $portfolioSummaryBuilder,
        SharedClientReportCsv $csv,
        ClientPortfolioReportingPeriod $reportingPeriod,
    ): StreamedResponse {
        $reportData = $this->reportData($token, $marketingSummaryBuilder, $portfolioSummaryBuilder, $reportingPeriod);
        $response = response()->streamDownload(function () use ($csv, $reportData): void {
            $stream = fopen('php://output', 'wb');
            $csv->write(
                $reportData['client'],
                $reportData['campaignRows'],
                $reportData['reportingCurrencyGroups'],
                $stream,
                $reportData['reportSections'],
                $reportData['reportPeriod'] === null
                    ? null
                    : $reportData['reportPeriod']['from'].' 至 '.$reportData['reportPeriod']['to'],
            );
            fclose($stream);
        }, $reportData['reportPeriod'] === null
            ? 'marketing-report-'.now()->format('Y-m-d').'.csv'
            : 'marketing-report-'.$reportData['reportPeriod']['from'].'-to-'.$reportData['reportPeriod']['to'].'.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * @return array{
     *     share: ClientReportShare,
     *     client: Client,
     *     campaignRows: Collection<int, array{target: SocialEngagementTarget, public_engagement: array<string, mixed>, marketing: array<string, mixed>}>,
     *     reportingCurrencyGroups: list<array<string, mixed>>,
     *     reportSections: list<string>,
     *     reportPeriod: array{from: string, to: string, label: string}|null
     * }
     */
    private function reportData(
        string $token,
        DashboardCampaignMarketingSummary $marketingSummaryBuilder,
        CampaignPortfolioMarketingSummary $portfolioSummaryBuilder,
        ClientPortfolioReportingPeriod $reportingPeriod,
    ): array {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $token) === 1, 404);

        $share = ClientReportShare::query()
            ->with(['client.user'])
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();
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
        $marketingSummaries = $marketingSummaryBuilder->build(
            $targets,
            $user,
            CarbonImmutable::now(),
            $publicEngagementSummaries,
        );

        if ($reportPeriod !== null) {
            $periodData = $reportingPeriod->apply(
                $targets,
                $marketingSummaries,
                $reportPeriod['from'],
                $reportPeriod['to'],
            );
            $targets = $periodData['targets'];
            $marketingSummaries = $periodData['marketing_summaries'];
        }

        $reportingCurrencySummary = $portfolioSummaryBuilder->build(
            $targets,
            $marketingSummaries,
            $client->exchangeRates()->get(),
        );
        $reportingCurrencyGroups = $reportingCurrencySummary['reporting_currency_groups'];
        $campaignRows = $targets->map(static fn (SocialEngagementTarget $target): array => [
            'target' => $target,
            'public_engagement' => $publicEngagementSummaries[$target->getKey()],
            'marketing' => $marketingSummaries[$target->getKey()],
        ]);

        return compact('share', 'client', 'campaignRows', 'reportingCurrencyGroups', 'reportSections', 'reportPeriod');
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
