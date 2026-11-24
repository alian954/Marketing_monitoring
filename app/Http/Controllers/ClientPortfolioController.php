<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientExchangeRate;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use App\Services\CampaignPortfolioMarketingSummary;
use App\Services\ClientPortfolioCsv;
use App\Services\ClientPortfolioReportingPeriod;
use App\Services\DashboardCampaignMarketingSummary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientPortfolioController extends Controller
{
    public function index(
        Request $request,
        DashboardCampaignMarketingSummary $dashboardCampaignMarketingSummary,
        CampaignPortfolioMarketingSummary $campaignPortfolioMarketingSummary,
        ClientPortfolioReportingPeriod $reportingPeriod,
    ): View|RedirectResponse {
        $reportPeriod = $this->validatedReportingPeriod($request, $reportingPeriod);

        if ($reportPeriod instanceof RedirectResponse) {
            return $reportPeriod;
        }

        $clientSearch = $this->validatedClientSearch($request);

        if ($clientSearch instanceof RedirectResponse) {
            return $clientSearch;
        }

        $clientPortfolioRows = $this->buildClientPortfolioRows(
            $this->authenticatedUser($request),
            $dashboardCampaignMarketingSummary,
            $campaignPortfolioMarketingSummary,
            $reportingPeriod,
            $reportPeriod,
            $clientSearch,
        );

        return view('clients.performance', compact('clientPortfolioRows', 'reportPeriod', 'clientSearch'));
    }

    public function export(
        Request $request,
        DashboardCampaignMarketingSummary $dashboardCampaignMarketingSummary,
        CampaignPortfolioMarketingSummary $campaignPortfolioMarketingSummary,
        ClientPortfolioCsv $csv,
        ClientPortfolioReportingPeriod $reportingPeriod,
    ): StreamedResponse|RedirectResponse {
        $reportPeriod = $this->validatedReportingPeriod($request, $reportingPeriod);

        if ($reportPeriod instanceof RedirectResponse) {
            return $reportPeriod;
        }

        $clientSearch = $this->validatedClientSearch($request);

        if ($clientSearch instanceof RedirectResponse) {
            return $clientSearch;
        }

        $clientPortfolioRows = $this->buildClientPortfolioRows(
            $this->authenticatedUser($request),
            $dashboardCampaignMarketingSummary,
            $campaignPortfolioMarketingSummary,
            $reportingPeriod,
            $reportPeriod,
            $clientSearch,
        );
        $response = response()->streamDownload(function () use ($clientPortfolioRows, $csv, $reportPeriod): void {
            $stream = fopen('php://output', 'wb');
            $csv->write($clientPortfolioRows, $stream, $reportPeriod['from'], $reportPeriod['to']);
            fclose($stream);
        }, 'client-performance-'.$reportPeriod['from'].'-to-'.$reportPeriod['to'].'.csv', [
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

    /** @return array{from: string, to: string}|RedirectResponse */
    private function validatedReportingPeriod(
        Request $request,
        ClientPortfolioReportingPeriod $reportingPeriod,
    ): array|RedirectResponse {
        $query = $request->query();

        if (! array_key_exists('from', $query) && ! array_key_exists('to', $query)) {
            return $reportingPeriod->defaultPeriod();
        }

        $latestCompleteDate = CarbonImmutable::now('Asia/Taipei')->startOfDay()->subDay()->toDateString();
        $validator = Validator::make($query, [
            'from' => ['required', 'string', 'date_format:Y-m-d'],
            'to' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$latestCompleteDate],
        ], [
            'from.required' => '請選擇報表開始日期。',
            'from.string' => '報表開始日期格式無效。',
            'from.date_format' => '報表開始日期格式無效。',
            'to.required' => '請選擇報表結束日期。',
            'to.string' => '報表結束日期格式無效。',
            'to.date_format' => '報表結束日期格式無效。',
            'to.after_or_equal' => '結束日期不可早於開始日期。',
            'to.before_or_equal' => '報表結束日期不可晚於昨天。',
        ]);
        $validator->after(function ($validator) use ($query): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = CarbonImmutable::createFromFormat('!Y-m-d', $query['from'], 'Asia/Taipei');
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $query['to'], 'Asia/Taipei');

            if ($from !== false && $to !== false && $from->addDays(364)->lessThan($to)) {
                $validator->errors()->add('from', '報表期間最多 365 天。');
            }
        });

        if ($validator->fails()) {
            return redirect()->route('clients.performance')
                ->withErrors($validator)
                ->withInput($query);
        }

        $validated = $validator->validated();

        return [
            'from' => $validated['from'],
            'to' => $validated['to'],
        ];
    }

    private function validatedClientSearch(Request $request): string|RedirectResponse
    {
        $validator = Validator::make($request->query(), [
            'search' => ['sometimes', 'nullable', 'string', 'max:80'],
        ], [
            'search.string' => '客戶搜尋格式無效。',
            'search.max' => '客戶搜尋不可超過 80 個字元。',
        ]);

        if ($validator->fails()) {
            return redirect()->route('clients.performance')
                ->withErrors($validator)
                ->withInput($request->query());
        }

        $search = $validator->validated()['search'] ?? '';

        return is_string($search) ? trim($search) : '';
    }

    /** @param array{from: string, to: string} $reportPeriod */
    private function buildClientPortfolioRows(
        User $user,
        DashboardCampaignMarketingSummary $dashboardCampaignMarketingSummary,
        CampaignPortfolioMarketingSummary $campaignPortfolioMarketingSummary,
        ClientPortfolioReportingPeriod $reportingPeriod,
        array $reportPeriod,
        string $clientSearch,
    ): Collection {
        Client::ensureDefaultFor($user);
        $clientQuery = Client::query()->orderBy('name');

        if ($clientSearch !== '') {
            $clientQuery->where('name', 'like', '%'.$clientSearch.'%');
        }

        $clients = $clientQuery->get();
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
        $periodData = $reportingPeriod->apply(
            $targets,
            $marketingSummaries,
            $reportPeriod['from'],
            $reportPeriod['to'],
        );
        $targets = $periodData['targets'];
        $marketingSummaries = $periodData['marketing_summaries'];
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
            $usesReportingCurrency = is_string($client->reporting_currency_code)
                && trim($client->reporting_currency_code) !== '';

            return [
                'client' => $client,
                'campaign_count' => $clientTargets->count(),
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
