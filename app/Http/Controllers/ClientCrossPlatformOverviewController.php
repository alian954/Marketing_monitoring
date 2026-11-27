<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use App\Services\ClientCrossPlatformOverview;
use App\Services\ClientPortfolioReportingPeriod;
use App\Services\DashboardCampaignMarketingSummary;
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
        DashboardCampaignMarketingSummary $dashboardCampaignMarketingSummary,
        ClientCrossPlatformOverview $crossPlatformOverview,
    ): Response|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $period = $this->validatedPeriod($request, $client, $reportingPeriod);

        if ($period instanceof RedirectResponse) {
            return $period;
        }

        $previousPeriod = $this->previousPeriod($period);
        $targets = SocialEngagementTarget::query()
            ->where('client_id', $client->getKey())
            ->whereDate('starts_on', '<=', $period['to'])
            ->whereDate('ends_on', '>=', $previousPeriod['from'])
            ->orderBy('id')
            ->get();
        $marketingSummaries = $dashboardCampaignMarketingSummary->build($targets, $user, now()->toImmutable());
        $overview = $crossPlatformOverview->build($targets, $marketingSummaries, $period['from'], $period['to']);
        $previousOverview = $crossPlatformOverview->build($targets, $marketingSummaries, $previousPeriod['from'], $previousPeriod['to']);

        return response()
            ->view('clients.cross-platform', [
                'client' => $client,
                'period' => $period,
                'previousPeriod' => $previousPeriod,
                'overview' => $overview,
                'comparison' => $crossPlatformOverview->compare($overview, $previousOverview),
                'chart' => $crossPlatformOverview->chart($overview['daily']),
                'campaignCount' => $targets->filter(
                    static fn (SocialEngagementTarget $target): bool => $target->starts_on !== null
                        && $target->ends_on !== null
                        && $target->starts_on->toDateString() <= $period['to']
                        && $target->ends_on->toDateString() >= $period['from'],
                )->count(),
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

    /**
     * @param  array{from: string, to: string}  $period
     * @return array{from: string, to: string}
     */
    private function previousPeriod(array $period): array
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
}
