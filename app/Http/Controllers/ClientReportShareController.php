<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientReportShare;
use App\Services\ClientPortfolioReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ClientReportShareController extends Controller
{
    public function index(Client $client, ClientPortfolioReportingPeriod $reportingPeriod): Response
    {
        $shares = $client->reportShares()->latest()->get();
        $campaignTargets = $client->socialEngagementTargets()
            ->orderBy('tag')
            ->orderBy('social_platform')
            ->get(['id', 'tag', 'social_platform']);

        return response()
            ->view('client-report-shares.index', [
                'client' => $client,
                'shares' => $shares,
                'campaignTargets' => $campaignTargets,
                'reportPeriod' => $reportingPeriod->defaultPeriod(),
            ])
            ->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $name = $request->input('name');

        if (is_string($name)) {
            $request->merge(['name' => trim($name)]);
        }

        $reportBrandName = $request->input('report_brand_name');

        if (is_string($reportBrandName)) {
            $request->merge(['report_brand_name' => trim($reportBrandName)]);
        }

        $latestCompleteDate = CarbonImmutable::now('Asia/Taipei')->startOfDay()->subDay()->toDateString();
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:80'],
            'report_brand_name' => ['nullable', 'string', 'max:80'],
            'expires_in_days' => ['required', 'integer', Rule::in([7, 30, 90])],
            'from' => ['required', 'string', 'date_format:Y-m-d'],
            'to' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$latestCompleteDate],
            'compare_previous_period' => ['sometimes', 'boolean'],
            'target_ids' => ['required', 'array', 'min:1'],
            'target_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('social_engagement_targets', 'id')->where('client_id', $client->getKey()),
            ],
            'report_sections' => ['sometimes', 'required', 'array', 'list', 'min:1'],
            'report_sections.*' => ['required', 'string', 'distinct', Rule::in(array_keys(ClientReportShare::REPORT_SECTION_LABELS))],
        ], [
            'name.required' => '請輸入分享連結名稱。',
            'name.max' => '分享連結名稱不可超過 80 個字元。',
            'report_brand_name.string' => '報表品牌名稱格式無效。',
            'report_brand_name.max' => '報表品牌名稱不可超過 80 個字元。',
            'expires_in_days.in' => '請選擇 7、30 或 90 天的有效期限。',
            'from.required' => '請選擇報表開始日期。',
            'from.string' => '報表開始日期格式無效。',
            'from.date_format' => '報表開始日期格式無效。',
            'to.required' => '請選擇報表結束日期。',
            'to.string' => '報表結束日期格式無效。',
            'to.date_format' => '報表結束日期格式無效。',
            'to.after_or_equal' => '結束日期不可早於開始日期。',
            'to.before_or_equal' => '報表結束日期不可晚於昨天。',
            'target_ids.required' => '請至少選擇一項要分享的活動。',
            'target_ids.array' => '活動選取格式不正確。',
            'target_ids.min' => '請至少選擇一項要分享的活動。',
            'target_ids.*.integer' => '活動選取格式不正確。',
            'target_ids.*.distinct' => '同一活動不可重複選取。',
            'target_ids.*.exists' => '所選活動不存在或不屬於此客戶。',
            'report_sections.required' => '請至少選擇一個報表區塊。',
            'report_sections.array' => '報表區塊選取格式不正確。',
            'report_sections.list' => '報表區塊選取格式不正確。',
            'report_sections.min' => '請至少選擇一個報表區塊。',
            'report_sections.*.in' => '所選報表區塊無效。',
            'report_sections.*.distinct' => '同一報表區塊不可重複選取。',
        ]);
        $validator->after(function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = CarbonImmutable::createFromFormat('!Y-m-d', (string) $validator->getData()['from'], 'Asia/Taipei');
            $to = CarbonImmutable::createFromFormat('!Y-m-d', (string) $validator->getData()['to'], 'Asia/Taipei');

            if ($from !== false && $to !== false && $from->addDays(364)->lessThan($to)) {
                $validator->errors()->add('from', '報表期間最多 365 天。');
            }

            $comparePreviousPeriod = in_array(
                $validator->getData()['compare_previous_period'] ?? null,
                [true, 1, '1'],
                true,
            );
            $reportSections = $validator->getData()['report_sections'] ?? ClientReportShare::DEFAULT_REPORT_SECTIONS;
            $hasMarketingMetrics = is_array($reportSections)
                && array_intersect($reportSections, [
                    ClientReportShare::SECTION_GOOGLE_ANALYTICS,
                    ClientReportShare::SECTION_META_MARKETING,
                    ClientReportShare::SECTION_GOOGLE_ADS,
                ]) !== [];

            if ($comparePreviousPeriod && ! $hasMarketingMetrics) {
                $validator->errors()->add('compare_previous_period', '前期比較需至少選擇一個 GA4 或廣告數據區塊。');
            }
        });

        if ($validator->fails()) {
            return redirect()
                ->route('clients.report-shares.index', $client)
                ->withErrors($validator)
                ->withInput($request->all());
        }

        $validated = $validator->validated();

        $token = bin2hex(random_bytes(32));
        $share = $client->reportShares()->create([
            'name' => $validated['name'],
            'report_brand_name' => $validated['report_brand_name'] ?? null,
            'token_hash' => hash('sha256', $token),
            'campaign_target_ids' => array_map('intval', $validated['target_ids']),
            'report_sections' => array_values($validated['report_sections'] ?? ClientReportShare::DEFAULT_REPORT_SECTIONS),
            'report_starts_on' => $validated['from'],
            'report_ends_on' => $validated['to'],
            'compare_previous_period' => in_array($validated['compare_previous_period'] ?? false, [true, 1, '1'], true),
            'expires_at' => now()->addDays((int) $validated['expires_in_days']),
        ]);

        return redirect()
            ->route('clients.report-shares.index', $client)
            ->with('status', '報表分享連結已建立。')
            ->with('createdShareUrl', route('client-report-shares.public', ['token' => $token]));
    }

    public function destroy(Client $client, int $reportShare): RedirectResponse
    {
        $share = $client->reportShares()->whereKey($reportShare)->firstOrFail();

        if ($share->revoked_at === null) {
            $share->forceFill(['revoked_at' => now()])->save();
        }

        return redirect()
            ->route('clients.report-shares.index', $client)
            ->with('status', '報表分享連結已撤銷。');
    }
}
