<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientExchangeRate;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use App\Services\CampaignPortfolioMarketingSummary;
use App\Services\ClientPortfolioCsv;
use App\Services\DashboardCampaignMarketingSummary;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientPortfolioController extends Controller
{
    public function index(
        Request $request,
        DashboardCampaignMarketingSummary $dashboardCampaignMarketingSummary,
        CampaignPortfolioMarketingSummary $campaignPortfolioMarketingSummary,
    ): View {
        $clientPortfolioRows = $this->buildClientPortfolioRows(
            $this->authenticatedUser($request),
            $dashboardCampaignMarketingSummary,
            $campaignPortfolioMarketingSummary,
        );

        return view('clients.performance', compact('clientPortfolioRows'));
    }

    public function export(
        Request $request,
        DashboardCampaignMarketingSummary $dashboardCampaignMarketingSummary,
        CampaignPortfolioMarketingSummary $campaignPortfolioMarketingSummary,
        ClientPortfolioCsv $csv,
    ): StreamedResponse {
        $clientPortfolioRows = $this->buildClientPortfolioRows(
            $this->authenticatedUser($request),
            $dashboardCampaignMarketingSummary,
            $campaignPortfolioMarketingSummary,
        );
        $response = response()->streamDownload(function () use ($clientPortfolioRows, $csv): void {
            $stream = fopen('php://output', 'wb');
            $csv->write($clientPortfolioRows, $stream);
            fclose($stream);
        }, 'client-performance-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function buildClientPortfolioRows(
        User $user,
        DashboardCampaignMarketingSummary $dashboardCampaignMarketingSummary,
        CampaignPortfolioMarketingSummary $campaignPortfolioMarketingSummary,
    ): Collection {
        Client::ensureDefaultFor($user);
        $clients = Client::query()
            ->withCount(['socialEngagementTargets'])
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

        return $clientPortfolioRows;
    }
}
