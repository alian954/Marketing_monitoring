<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateClientMarketingInsight;
use App\Models\Client;
use App\Models\ClientMarketingInsight;
use App\Models\User;
use App\Services\ClientCrossPlatformReport;
use App\Services\ClientMarketingInsightPayloadBuilder;
use App\Services\MarketingInsightWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ClientMarketingInsightController extends Controller
{
    private const MAX_PERIOD_DAYS = 92;

    private const DAILY_LIMIT_PER_CLIENT = 20;

    public function store(
        Request $request,
        Client $client,
        ClientCrossPlatformReport $crossPlatformReport,
        ClientMarketingInsightPayloadBuilder $payloadBuilder,
        MarketingInsightWriter $insightWriter,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $latestCompleteDate = CarbonImmutable::now('Asia/Taipei')->startOfDay()->subDay()->toDateString();
        $input = $request->only(['from', 'to']);
        $validator = Validator::make($input, [
            'from' => ['required', 'string', 'date_format:Y-m-d'],
            'to' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$latestCompleteDate],
        ], [
            'from.*' => '報表期間無效。',
            'to.*' => '報表期間無效。',
        ]);
        $validator->after(function ($validator) use ($input, $insightWriter, $client): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = CarbonImmutable::createFromFormat('!Y-m-d', $input['from'], 'Asia/Taipei');
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $input['to'], 'Asia/Taipei');

            if ($from->addDays(self::MAX_PERIOD_DAYS - 1)->lessThan($to)) {
                $validator->errors()->add('insight', '期間最多 '.self::MAX_PERIOD_DAYS.' 天。');
            }

            if (! $insightWriter->isConfigured()) {
                $validator->errors()->add('insight', '尚未設定 AI 摘要所需的 ANTHROPIC_API_KEY，請聯絡系統管理員。');
            }

            if ($client->marketingInsights()->where('created_at', '>=', now()->subDay())->count() >= self::DAILY_LIMIT_PER_CLIENT) {
                $validator->errors()->add('insight', '此客戶 24 小時內最多可產生 '.self::DAILY_LIMIT_PER_CLIENT.' 次 AI 摘要。');
            }
        });

        $period = is_string($input['from'] ?? null) && is_string($input['to'] ?? null)
            ? ['client' => $client, 'from' => $input['from'], 'to' => $input['to']]
            : ['client' => $client];

        if ($validator->fails()) {
            return redirect()
                ->route('clients.cross-platform', $period)
                ->withErrors($validator->errors()->has('insight') ? $validator : ['insight' => '報表期間無效。']);
        }

        $pending = $client->marketingInsights()
            ->whereDate('from_on', $input['from'])
            ->whereDate('to_on', $input['to'])
            ->where('status', ClientMarketingInsight::STATUS_PENDING)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->exists();

        if ($pending) {
            return redirect()
                ->route('clients.cross-platform', $period)
                ->with('status', 'AI 摘要產生中，請稍後重新整理頁面。');
        }

        $report = $crossPlatformReport->build($client, $user, ['from' => $input['from'], 'to' => $input['to']]);
        $insight = $client->marketingInsights()->create([
            'from_on' => $input['from'],
            'to_on' => $input['to'],
            'status' => ClientMarketingInsight::STATUS_PENDING,
            'input_payload' => $payloadBuilder->build(
                $client,
                $report['overview'],
                $report['comparison'],
                $report['previous_period'],
                $report['timeline'],
                $report['budget_pacing'],
                $report['store_revenue'],
                $report['lead_funnel'],
            ),
            'requested_by_user_id' => $user->getKey(),
        ]);

        GenerateClientMarketingInsight::dispatch((int) $insight->getKey());

        return redirect()
            ->route('clients.cross-platform', $period)
            ->with('status', '已開始產生 AI 摘要，通常需要 30 秒到 1 分鐘，完成後重新整理頁面即可看到。');
    }
}
