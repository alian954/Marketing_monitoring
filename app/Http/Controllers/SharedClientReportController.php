<?php

namespace App\Http\Controllers;

use App\Models\ClientReportShare;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use App\Services\CampaignPortfolioMarketingSummary;
use App\Services\DashboardCampaignMarketingSummary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;

class SharedClientReportController extends Controller
{
    public function __invoke(
        string $token,
        DashboardCampaignMarketingSummary $marketingSummaryBuilder,
        CampaignPortfolioMarketingSummary $portfolioSummaryBuilder,
    ): Response {
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
        $monitors = $client->monitors()
            ->whereNotNull('social_platform')
            ->with(['latestPublicEngagementCheckRun'])
            ->get();
        $publicEngagementSummaries = $this->publicEngagementSummaries($targets, $monitors);
        $marketingSummaries = $marketingSummaryBuilder->build(
            $targets,
            $user,
            CarbonImmutable::now(),
            $publicEngagementSummaries,
        );
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

        return response()
            ->view('shared-client-reports.show', compact('share', 'client', 'campaignRows', 'reportingCurrencyGroups'))
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * @param  Collection<int, SocialEngagementTarget>  $targets
     * @param  Collection<int, Monitor>  $monitors
     * @return array<int, array{interaction_total: int|null, available_posts: int, tracked_posts: int, views_total: int|null, latest_at: CarbonImmutable|null}>
     */
    private function publicEngagementSummaries(Collection $targets, Collection $monitors): array
    {
        $summaries = [];

        foreach ($targets as $target) {
            $startsOn = $target->starts_on?->toDateString();
            $endsOn = $target->ends_on?->toDateString();
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
