<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\ClientAdPlatformMetricImporter;
use App\Services\ClientCrossPlatformOverview;
use App\Services\MarketingCsvException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ClientAdPlatformImportController extends Controller
{
    private const CAMPAIGN_LIMIT = 50;

    public function index(Client $client): Response
    {
        $campaigns = $client->adPlatformDailyMetrics()
            ->selectRaw('platform, campaign_name, currency_code, SUM(spend) as spend_total, SUM(impressions) as impressions_total, SUM(clicks) as clicks_total, SUM(conversions) as conversions_total, SUM(conversion_value) as value_total, MIN(metric_on) as first_on, MAX(metric_on) as last_on, COUNT(*) as day_count')
            ->groupBy('platform', 'campaign_name', 'currency_code')
            ->orderByDesc('last_on')
            ->limit(self::CAMPAIGN_LIMIT)
            ->get();

        return response()
            ->view('clients.ad-imports', [
                'client' => $client,
                'platforms' => ClientCrossPlatformOverview::IMPORTED_SOURCES,
                'campaigns' => $campaigns,
                'defaultCurrencyCode' => $client->reporting_currency_code ?? 'TWD',
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Client $client, ClientAdPlatformMetricImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'string', Rule::in(array_keys(ClientCrossPlatformOverview::IMPORTED_SOURCES))],
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'currency_code' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
        ], [
            'platform.required' => '請選擇廣告平台。',
            'platform.in' => '所選廣告平台無效。',
            'csv_file.required' => '請選擇 CSV 檔案。',
            'csv_file.mimes' => '只支援 CSV 檔案。',
            'csv_file.max' => 'CSV 檔案不可超過 5 MB。',
            'currency_code.required' => '請輸入幣別。',
            'currency_code.regex' => '幣別必須是 3 碼英文代碼，例如 TWD。',
        ]);

        try {
            $result = $importer->import(
                $client,
                $validated['platform'],
                $validated['csv_file']->getRealPath(),
                strtoupper($validated['currency_code']),
            );
        } catch (MarketingCsvException $exception) {
            return redirect()
                ->route('clients.ad-imports.index', $client)
                ->withErrors(['csv_file' => $exception->getMessage()])
                ->withInput($request->only(['platform', 'currency_code']));
        }

        return redirect()
            ->route('clients.ad-imports.index', $client)
            ->with('importResult', $result + ['platform' => $validated['platform']])
            ->with('status', $result['imported'] > 0 ? '已匯入 '.number_format($result['imported']).' 筆每日數據。' : '沒有可匯入的資料。');
    }

    public function destroy(Request $request, Client $client): RedirectResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'string', Rule::in(array_keys(ClientCrossPlatformOverview::IMPORTED_SOURCES))],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'platform.*' => '請選擇廣告平台。',
            'from.*' => '請選擇有效的開始日期。',
            'to.*' => '請選擇有效的結束日期，且不可早於開始日期。',
        ]);

        $deleted = $client->adPlatformDailyMetrics()
            ->where('platform', $validated['platform'])
            ->whereDate('metric_on', '>=', $validated['from'])
            ->whereDate('metric_on', '<=', $validated['to'])
            ->delete();

        return redirect()
            ->route('clients.ad-imports.index', $client)
            ->with('status', '已刪除 '.number_format($deleted).' 筆'.ClientCrossPlatformOverview::IMPORTED_SOURCES[$validated['platform']]['label'].'數據。');
    }
}
