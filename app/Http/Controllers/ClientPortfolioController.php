<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientExchangeRate;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use App\Services\CampaignPortfolioMarketingSummary;
use App\Services\DashboardCampaignMarketingSummary;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClientPortfolioController extends Controller
{
    public function index(
        Request $request,
        DashboardCampaignMarketingSummary $dashboardCampaignMarketingSummary,
        CampaignPortfolioMarketingSummary $campaignPortfolioMarketingSummary,
    ): View {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        Client::ensureDefaultFor($user);

        $clients = Client::query()
            ->withCount(['monitors', 'socialEngagementTargets'])
            ->orderBy('name')
            ->get();
        $clientIds = $clients->modelKeys();
        $targets = $clientIds === []
            ? new EloquentCollection
            : SocialEngagementTarget::query()
                ->whereIn('client_id', $clientIds)
                ->with('client:id,reporting_currency_code')
                ->orderBy('tag')
                ->orderBy('social_platform')
                ->get();
        $asOf = now()->toImmutable();
        $marketingSummaries = $dashboardCampaignMarketingSummary->build($targets, $user, $asOf);
        $exchangeRates = ClientExchangeRate::query()
            ->whereIn('client_id', $clientIds)
            ->get()
            ->groupBy('client_id');
        $targetsByClient = $targets->groupBy('client_id');

        $clientPortfolioRows = $clients->map(function (Client $client) use (
            $campaignPortfolioMarketingSummary,
            $exchangeRates,
            $marketingSummaries,
            $targetsByClient,
        ): array {
            $clientTargets = $targetsByClient->get($client->getKey(), new EloquentCollection);
            $clientMarketingSummaries = $clientTargets
                ->mapWithKeys(static fn (SocialEngagementTarget $target): array => [
                    $target->getKey() => $marketingSummaries[$target->getKey()] ?? [],
                ])
                ->all();
            $clientExchangeRates = $exchangeRates->get($client->getKey(), new EloquentCollection);
            $portfolio = $campaignPortfolioMarketingSummary->build(
                $clientTargets,
                $clientMarketingSummaries,
                $clientExchangeRates,
            );
            $usesReportingCurrency = $portfolio['reporting_currency_groups'] !== [];

            return [
                'client' => $client,
                'campaign_count' => (int) $client->social_engagement_targets_count,
                'money_groups' => $usesReportingCurrency
                    ? $portfolio['reporting_currency_groups']
                    : $portfolio['currency_groups'],
                'roas_groups' => $portfolio['native_roas_groups'],
                'uses_reporting_currency' => $usesReportingCurrency,
                'coverage' => $portfolio['coverage'],
            ];
        });

        return view('clients.performance', compact('clientPortfolioRows'));
    }
}
