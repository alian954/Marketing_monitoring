<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use App\Services\ClientCrossPlatformReport;
use App\Services\ClientPortfolioReportingPeriod;
use App\Services\MarketingInsightWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class ClientCrossPlatformOverviewController extends Controller
{
    private const MAX_PERIOD_DAYS = 92;

    public function show(
        Request $request,
        Client $client,
        ClientPortfolioReportingPeriod $reportingPeriod,
        ClientCrossPlatformReport $crossPlatformReport,
        MarketingInsightWriter $insightWriter,
    ): Response|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $period = $this->validatedPeriod($request, $client, $reportingPeriod);

        if ($period instanceof RedirectResponse) {
            return $period;
        }

        $report = $crossPlatformReport->build($client, $user, $period);

        return response()
            ->view('clients.cross-platform', [
                'client' => $client,
                'period' => $period,
                'previousPeriod' => $report['previous_period'],
                'overview' => $report['overview'],
                'comparison' => $report['comparison'],
                'chart' => $report['chart'],
                'timeline' => $report['timeline'],
                'chartMarkers' => $report['chart_markers'],
                'campaignCount' => $report['campaign_count'],
                'budgetPacing' => $report['budget_pacing'],
                'storeRevenue' => $report['store_revenue'],
                'leadFunnel' => $report['lead_funnel'],
                'insight' => $client->marketingInsights()
                    ->whereDate('from_on', $period['from'])
                    ->whereDate('to_on', $period['to'])
                    ->latest('id')
                    ->first(),
                'insightAvailable' => $insightWriter->isConfigured(),
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    /** @return array{from: string, to: string}|RedirectResponse */
    private function validatedPeriod(Request $request, Client $client, ClientPortfolioReportingPeriod $reportingPeriod): array|RedirectResponse
    {
        $query = $request->query();

        if (! array_key_exists('from', $query) && ! array_key_exists('to', $query)) {
            return $reportingPeriod->defaultPeriod();
        }

        $latestCompleteDate = CarbonImmutable::now('Asia/Taipei')->startOfDay()->subDay()->toDateString();
        $validator = Validator::make($query, [
            'from' => ['required', 'string', 'date_format:Y-m-d'],
            'to' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$latestCompleteDate],
        ], [
            'from.required' => '請選擇開始日期。',
            'from.date_format' => '開始日期格式無效。',
            'to.required' => '請選擇結束日期。',
            'to.date_format' => '結束日期格式無效。',
            'to.after_or_equal' => '結束日期不可早於開始日期。',
            'to.before_or_equal' => '結束日期不可晚於昨天。',
        ]);
        $validator->after(function ($validator) use ($query): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = CarbonImmutable::createFromFormat('!Y-m-d', $query['from'], 'Asia/Taipei');
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $query['to'], 'Asia/Taipei');

            if ($from->addDays(self::MAX_PERIOD_DAYS - 1)->lessThan($to)) {
                $validator->errors()->add('from', '期間最多 '.self::MAX_PERIOD_DAYS.' 天。');
            }
        });

        if ($validator->fails()) {
            return redirect()->route('clients.cross-platform', $client)
                ->withErrors($validator)
                ->withInput($request->only(['from', 'to']));
        }

        return ['from' => $query['from'], 'to' => $query['to']];
    }
}
