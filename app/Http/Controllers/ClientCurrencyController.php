<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientExchangeRate;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientCurrencyController extends Controller
{
    public function index(Client $client): View
    {
        $exchangeRates = $client->exchangeRates()
            ->orderBy('reporting_currency_code')
            ->orderBy('source_currency_code')
            ->orderByDesc('effective_on')
            ->orderByDesc('id')
            ->get();

        return view('client-currencies.index', compact('client', 'exchangeRates'));
    }

    public function updateReportingCurrency(Request $request, Client $client): RedirectResponse
    {
        $requestedCurrencyCode = $request->input('reporting_currency_code');
        $request->merge([
            'reporting_currency_code' => is_string($requestedCurrencyCode)
                ? strtoupper(trim($requestedCurrencyCode))
                : null,
        ]);
        $validated = $request->validate([
            'reporting_currency_code' => ['nullable', 'string', 'size:3', 'regex:/\A[A-Z]{3}\z/'],
        ], [
            'reporting_currency_code.size' => '報表幣別請輸入 3 碼 ISO 4217 代碼，例如 TWD 或 USD。',
            'reporting_currency_code.regex' => '報表幣別請輸入 3 碼 ISO 4217 代碼，例如 TWD 或 USD。',
        ]);

        $client->update(['reporting_currency_code' => $validated['reporting_currency_code']]);

        return redirect()->route('clients.currencies.index', $client)
            ->with('status', $client->reporting_currency_code === null
                ? '已停用報表幣別換算。'
                : '報表幣別已更新為 '.$client->reporting_currency_code.'。');
    }

    public function storeExchangeRate(Request $request, Client $client): RedirectResponse
    {
        if ($client->reporting_currency_code === null) {
            return back()->withErrors(['reporting_currency_code' => '請先設定報表幣別。']);
        }

        $request->merge([
            'source_currency_code' => strtoupper(trim((string) $request->input('source_currency_code'))),
            'reporting_currency_code' => strtoupper(trim((string) $request->input('reporting_currency_code'))),
        ]);
        $validated = $request->validateWithBag('createExchangeRate', [
            ...$this->exchangeRateRules(),
            'reporting_currency_code' => ['required', Rule::in([$client->reporting_currency_code])],
            'source_currency_code' => [
                'required', 'string', 'size:3', 'regex:/\A[A-Z]{3}\z/',
                Rule::notIn([$client->reporting_currency_code]),
                Rule::unique('client_exchange_rates', 'source_currency_code')
                    ->where('client_id', $client->getKey())
                    ->where('reporting_currency_code', $client->reporting_currency_code)
                    ->where(fn (Builder $query): Builder => $query->whereDate('effective_on', $request->input('effective_on'))),
            ],
        ], [
            'reporting_currency_code.in' => '報表幣別已變更，請重新載入後再新增匯率。',
            'source_currency_code.size' => '來源幣別請輸入 3 碼 ISO 4217 代碼。',
            'source_currency_code.regex' => '來源幣別請輸入 3 碼 ISO 4217 代碼。',
            'source_currency_code.not_in' => '來源幣別與報表幣別相同時不需設定匯率。',
            'source_currency_code.unique' => '此來源幣別在該生效日已有匯率。',
            'effective_on.date_format' => '請輸入有效的生效日期。',
            'rate_to_reporting_currency.decimal' => '匯率最多可輸入 8 位小數。',
            'rate_to_reporting_currency.gt' => '匯率必須大於 0。',
        ]);

        $client->exchangeRates()->create([
            'source_currency_code' => $validated['source_currency_code'],
            'reporting_currency_code' => $client->reporting_currency_code,
            'effective_on' => $validated['effective_on'],
            'rate_to_reporting_currency' => $validated['rate_to_reporting_currency'],
        ]);

        return redirect()->route('clients.currencies.index', $client)->with('status', '匯率已新增。');
    }

    public function updateExchangeRate(
        Request $request,
        Client $client,
        ClientExchangeRate $exchangeRate,
    ): RedirectResponse {
        $exchangeRate = $client->exchangeRates()->findOrFail($exchangeRate->getKey());
        $sourceCurrencyCode = strtoupper(trim((string) $request->input('source_currency_code')));
        $request->merge(['source_currency_code' => $sourceCurrencyCode]);
        $validated = $request->validateWithBag('exchangeRate'.$exchangeRate->getKey(), [
            ...$this->exchangeRateRules(),
            'source_currency_code' => [
                'required', 'string', 'size:3', 'regex:/\A[A-Z]{3}\z/',
                Rule::notIn([$exchangeRate->reporting_currency_code]),
                Rule::unique('client_exchange_rates', 'source_currency_code')
                    ->where('client_id', $client->getKey())
                    ->where('reporting_currency_code', $exchangeRate->reporting_currency_code)
                    ->where(fn (Builder $query): Builder => $query->whereDate('effective_on', $request->input('effective_on')))
                    ->ignore($exchangeRate->getKey()),
            ],
        ], [
            'source_currency_code.size' => '來源幣別請輸入 3 碼 ISO 4217 代碼。',
            'source_currency_code.regex' => '來源幣別請輸入 3 碼 ISO 4217 代碼。',
            'source_currency_code.not_in' => '來源幣別與報表幣別相同時不需設定匯率。',
            'source_currency_code.unique' => '此來源幣別在該生效日已有匯率。',
            'effective_on.date_format' => '請輸入有效的生效日期。',
            'rate_to_reporting_currency.decimal' => '匯率最多可輸入 8 位小數。',
            'rate_to_reporting_currency.gt' => '匯率必須大於 0。',
        ]);

        $exchangeRate->update($validated);

        return redirect()->route('clients.currencies.index', $client)->with('status', '匯率已更新。');
    }

    public function destroyExchangeRate(
        Client $client,
        ClientExchangeRate $exchangeRate,
    ): RedirectResponse {
        $client->exchangeRates()->findOrFail($exchangeRate->getKey())->delete();

        return redirect()->route('clients.currencies.index', $client)->with('status', '匯率已刪除。');
    }

    /** @return array<string, array<int, string>> */
    private function exchangeRateRules(): array
    {
        return [
            'effective_on' => ['required', 'date_format:Y-m-d'],
            'rate_to_reporting_currency' => ['required', 'numeric', 'decimal:0,8', 'gt:0', 'max:999999999999.99999999'],
        ];
    }
}
