<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientMonthlyBudget;
use App\Models\User;
use App\Services\ClientCrossPlatformReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class ClientBudgetController extends Controller
{
    private const HISTORY_LIMIT = 6;

    private const MAX_AMOUNT = 9999999999999;

    public function __construct(private readonly ClientCrossPlatformReport $crossPlatformReport) {}

    public function index(Request $request, Client $client): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $now = now()->toImmutable();
        $currentMonth = $now->setTimezone('Asia/Taipei')->format('Y-m');
        $month = $request->query('month', $currentMonth);

        if (! is_string($month) || ! $this->isValidMonth($month)) {
            return redirect()->route('clients.budgets.index', $client);
        }

        $budget = $client->monthlyBudgets()->where('month', $month)->first();
        $pacing = $budget === null ? null : $this->pacingFor($budget, $client, $user, $now);
        $history = $client->monthlyBudgets()
            ->where('month', '<', $month)
            ->orderByDesc('month')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (ClientMonthlyBudget $pastBudget): array => [
                'budget' => $pastBudget,
                'pacing' => $this->pacingFor($pastBudget, $client, $user, $now),
            ]);
        $monthStart = CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Taipei');

        return response()
            ->view('clients.budgets', [
                'client' => $client,
                'month' => $month,
                'currentMonth' => $currentMonth,
                'previousMonth' => $monthStart->subMonthNoOverflow()->format('Y-m'),
                'nextMonth' => $monthStart->addMonthNoOverflow()->format('Y-m'),
                'budget' => $budget,
                'pacing' => $pacing,
                'history' => $history,
                'defaultCurrencyCode' => $client->reporting_currency_code ?? 'TWD',
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $input = $request->only(['month', 'currency_code', 'total_budget', 'google_ads_budget', 'meta_marketing_budget', 'note']);
        $input['currency_code'] = is_string($input['currency_code'] ?? null) ? strtoupper(trim($input['currency_code'])) : null;

        foreach (['google_ads_budget', 'meta_marketing_budget', 'note'] as $optional) {
            if (($input[$optional] ?? null) === '') {
                $input[$optional] = null;
            }
        }

        $validator = Validator::make($input, [
            'month' => ['required', 'string', 'date_format:Y-m'],
            'currency_code' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'total_budget' => ['required', 'numeric', 'gt:0', 'max:'.self::MAX_AMOUNT],
            'google_ads_budget' => ['nullable', 'numeric', 'gt:0', 'max:'.self::MAX_AMOUNT],
            'meta_marketing_budget' => ['nullable', 'numeric', 'gt:0', 'max:'.self::MAX_AMOUNT],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'month.required' => '請選擇月份。',
            'month.date_format' => '月份格式無效。',
            'currency_code.required' => '請輸入幣別。',
            'currency_code.regex' => '幣別必須是 3 碼英文代碼，例如 TWD。',
            'total_budget.required' => '請輸入總預算。',
            'total_budget.numeric' => '總預算必須是數字。',
            'total_budget.gt' => '總預算必須大於 0。',
            'total_budget.max' => '總預算過大。',
            'google_ads_budget.numeric' => 'Google Ads 預算必須是數字。',
            'google_ads_budget.gt' => 'Google Ads 預算必須大於 0。',
            'google_ads_budget.max' => 'Google Ads 預算過大。',
            'meta_marketing_budget.numeric' => 'Meta Ads 預算必須是數字。',
            'meta_marketing_budget.gt' => 'Meta Ads 預算必須大於 0。',
            'meta_marketing_budget.max' => 'Meta Ads 預算過大。',
            'note.max' => '備註不得超過 500 個字。',
        ]);
        $validator->after(function ($validator) use ($input): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $this->isValidMonth((string) $input['month'])) {
                $validator->errors()->add('month', '月份超出可設定範圍。');
            }

            $platformTotal = (float) ($input['google_ads_budget'] ?? 0) + (float) ($input['meta_marketing_budget'] ?? 0);

            if ($platformTotal > (float) $input['total_budget'] + 0.001) {
                $validator->errors()->add('total_budget', '各平台預算加總不可超過總預算。');
            }
        });

        if ($validator->fails()) {
            return redirect()
                ->route('clients.budgets.index', ['client' => $client, 'month' => is_string($input['month'] ?? null) && $this->isValidMonth($input['month']) ? $input['month'] : null])
                ->withErrors($validator)
                ->withInput($input);
        }

        $validated = $validator->validated();
        $note = isset($validated['note']) ? trim($validated['note']) : '';
        $client->monthlyBudgets()->updateOrCreate(
            ['month' => $validated['month']],
            [
                'currency_code' => $validated['currency_code'],
                'total_budget' => (string) $validated['total_budget'],
                'google_ads_budget' => isset($validated['google_ads_budget']) ? (string) $validated['google_ads_budget'] : null,
                'meta_marketing_budget' => isset($validated['meta_marketing_budget']) ? (string) $validated['meta_marketing_budget'] : null,
                'note' => $note === '' ? null : $note,
            ],
        );

        return redirect()
            ->route('clients.budgets.index', ['client' => $client, 'month' => $validated['month']])
            ->with('status', '已儲存 '.$validated['month'].' 的月預算。');
    }

    public function destroy(Client $client, ClientMonthlyBudget $monthlyBudget): RedirectResponse
    {
        $month = $monthlyBudget->month;
        $monthlyBudget->delete();

        return redirect()
            ->route('clients.budgets.index', ['client' => $client, 'month' => $month])
            ->with('status', '已刪除 '.$month.' 的月預算。');
    }

    /** @return array<string, mixed> */
    private function pacingFor(ClientMonthlyBudget $budget, Client $client, User $user, CarbonImmutable $now): array
    {
        return $this->crossPlatformReport->budgetPacing($budget, $client, $user, $now);
    }

    private function isValidMonth(string $month): bool
    {
        if (preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return false;
        }

        return $month >= '2020-01' && $month <= '2099-12';
    }
}
