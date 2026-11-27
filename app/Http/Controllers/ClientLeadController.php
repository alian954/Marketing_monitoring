<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientLead;
use App\Models\User;
use App\Services\ClientCrossPlatformReport;
use App\Services\ClientLeadImporter;
use App\Services\ClientPortfolioReportingPeriod;
use App\Services\CsvFormulaSanitizer;
use App\Services\MarketingCsvException;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientLeadController extends Controller
{
    private const MAX_PERIOD_DAYS = 92;

    public function index(Request $request, Client $client, ClientPortfolioReportingPeriod $reportingPeriod, ClientCrossPlatformReport $crossPlatformReport): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $period = $this->period($request, $reportingPeriod);

        if ($period === null) {
            return redirect()->route('clients.leads.index', $client)->withErrors(['from' => '期間無效，最多 '.self::MAX_PERIOD_DAYS.' 天且結束日期最晚為昨天。']);
        }

        $report = $crossPlatformReport->build($client, $user, $period);

        return response()
            ->view('clients.leads', [
                'client' => $client,
                'period' => $period,
                'funnel' => $report['lead_funnel'],
                'leadCount' => $client->leads()->count(),
                'exportableCount' => $client->leads()
                    ->where('stage', ClientLead::STAGE_WON)
                    ->whereNotNull('google_click_id')
                    ->whereDate('won_on', '>=', $period['from'])
                    ->whereDate('won_on', '<=', $period['to'])
                    ->count(),
                'defaultCurrencyCode' => $client->reporting_currency_code ?? 'TWD',
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Client $client, ClientLeadImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'currency_code' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
        ], [
            'csv_file.required' => '請選擇 CSV 檔案。',
            'csv_file.mimes' => '只支援 CSV 檔案。',
            'csv_file.max' => 'CSV 檔案不可超過 5 MB。',
            'currency_code.required' => '請輸入幣別。',
            'currency_code.regex' => '幣別必須是 3 碼英文代碼，例如 TWD。',
        ]);

        try {
            $result = $importer->import($client, $validated['csv_file']->getRealPath(), strtoupper($validated['currency_code']));
        } catch (MarketingCsvException $exception) {
            return redirect()
                ->route('clients.leads.index', $client)
                ->withErrors(['csv_file' => $exception->getMessage()])
                ->withInput($request->only(['currency_code']));
        }

        return redirect()
            ->route('clients.leads.index', $client)
            ->with('importResult', $result)
            ->with('status', $result['imported'] > 0 ? '已匯入 '.number_format($result['imported']).' 筆名單。' : '沒有可匯入的名單。');
    }

    /**
     * Downloads won leads with a Google click ID in the Google Ads offline conversion import format.
     */
    public function exportOfflineConversions(Request $request, Client $client, ClientPortfolioReportingPeriod $reportingPeriod): StreamedResponse|RedirectResponse
    {
        $period = $this->period($request, $reportingPeriod);
        $conversionName = trim((string) $request->query('conversion_name', ''));

        if ($period === null || $conversionName === '' || mb_strlen($conversionName) > 100) {
            return redirect()
                ->route('clients.leads.index', $client)
                ->withErrors(['conversion_name' => '請輸入 Google Ads 中的離線轉換動作名稱（100 字以內），並選擇有效期間。']);
        }

        $leads = $client->leads()
            ->where('stage', ClientLead::STAGE_WON)
            ->whereNotNull('google_click_id')
            ->whereDate('won_on', '>=', $period['from'])
            ->whereDate('won_on', '<=', $period['to'])
            ->orderBy('won_on')
            ->get();

        return response()->streamDownload(function () use ($leads, $conversionName): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Parameters:TimeZone=+0800'], ',', '"', '');
            fputcsv($stream, ['Google Click ID', 'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency'], ',', '"', '');

            foreach ($leads as $lead) {
                /** @var ClientLead $lead */
                fputcsv($stream, [
                    CsvFormulaSanitizer::escape((string) $lead->google_click_id),
                    CsvFormulaSanitizer::escape($conversionName),
                    $lead->won_on?->format('Y-m-d').' 12:00:00',
                    $lead->deal_value === null ? '' : (string) $lead->deal_value,
                    $lead->currency_code,
                ], ',', '"', '');
            }

            fclose($stream);
        }, 'google-ads-offline-conversions-'.$period['from'].'-'.$period['to'].'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
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

        $deleted = $client->leads()
            ->whereDate('created_on', '>=', $validated['from'])
            ->whereDate('created_on', '<=', $validated['to'])
            ->delete();

        return redirect()
            ->route('clients.leads.index', $client)
            ->with('status', '已刪除 '.number_format($deleted).' 筆名單。');
    }

    /** @return array{from: string, to: string}|null */
    private function period(Request $request, ClientPortfolioReportingPeriod $reportingPeriod): ?array
    {
        if (! $request->has('from') && ! $request->has('to')) {
            return $reportingPeriod->defaultPeriod();
        }

        $input = $request->only(['from', 'to']);
        $latestCompleteDate = CarbonImmutable::now('Asia/Taipei')->startOfDay()->subDay()->toDateString();
        $validator = Validator::make($input, [
            'from' => ['required', 'string', 'date_format:Y-m-d'],
            'to' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$latestCompleteDate],
        ]);

        if ($validator->fails()
            || CarbonImmutable::createFromFormat('!Y-m-d', $input['from'])->addDays(self::MAX_PERIOD_DAYS - 1)->lessThan(CarbonImmutable::createFromFormat('!Y-m-d', $input['to']))) {
            return null;
        }

        return ['from' => $input['from'], 'to' => $input['to']];
    }
}
