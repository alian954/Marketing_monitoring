<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\ClientStoreOrderImporter;
use App\Services\MarketingCsvException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ClientStoreRevenueController extends Controller
{
    private const MONTH_LIMIT = 12;

    public function index(Client $client): Response
    {
        $monthExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', ordered_on)"
            : "DATE_FORMAT(ordered_on, '%Y-%m')";
        $months = $client->storeOrders()
            ->selectRaw($monthExpression.' as month, currency_code, SUM(revenue) as revenue_total, SUM(orders_count) as orders_total, SUM(new_customer_orders) as new_total, COUNT(new_customer_orders) as new_known, MIN(ordered_on) as first_on, MAX(ordered_on) as last_on')
            ->groupByRaw($monthExpression.', currency_code')
            ->orderByDesc('month')
            ->limit(self::MONTH_LIMIT)
            ->get();

        return response()
            ->view('clients.store-revenue', [
                'client' => $client,
                'months' => $months,
                'orderCount' => $client->storeOrders()->count(),
                'lastImportedAt' => $client->storeOrders()->max('updated_at'),
                'defaultCurrencyCode' => $client->reporting_currency_code ?? 'TWD',
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Client $client, ClientStoreOrderImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'currency_code' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'source_label' => ['nullable', 'string', 'max:60'],
        ], [
            'csv_file.required' => '請選擇 CSV 檔案。',
            'csv_file.mimes' => '只支援 CSV 檔案。',
            'csv_file.max' => 'CSV 檔案不可超過 5 MB。',
            'currency_code.required' => '請輸入幣別。',
            'currency_code.regex' => '幣別必須是 3 碼英文代碼，例如 TWD。',
            'source_label.max' => '來源名稱不得超過 60 個字。',
        ]);
        $sourceLabel = trim((string) ($validated['source_label'] ?? ''));

        try {
            $result = $importer->import(
                $client,
                $validated['csv_file']->getRealPath(),
                strtoupper($validated['currency_code']),
                $sourceLabel === '' ? null : $sourceLabel,
            );
        } catch (MarketingCsvException $exception) {
            return redirect()
                ->route('clients.store-revenue.index', $client)
                ->withErrors(['csv_file' => $exception->getMessage()])
                ->withInput($request->only(['currency_code', 'source_label']));
        }

        return redirect()
            ->route('clients.store-revenue.index', $client)
            ->with('importResult', $result)
            ->with('status', $result['imported'] > 0 ? '已匯入 '.number_format($result['imported']).' 筆營收資料。' : '沒有可匯入的資料。');
    }

    public function destroy(Request $request, Client $client): RedirectResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'from.*' => '請選擇有效的開始日期。',
            'to.*' => '請選擇有效的結束日期，且不可早於開始日期。',
        ]);

        $deleted = $client->storeOrders()
            ->whereDate('ordered_on', '>=', $validated['from'])
            ->whereDate('ordered_on', '<=', $validated['to'])
            ->delete();

        return redirect()
            ->route('clients.store-revenue.index', $client)
            ->with('status', '已刪除 '.number_format($deleted).' 筆營收資料。');
    }
}
