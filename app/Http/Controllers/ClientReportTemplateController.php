<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientReportShare;
use App\Models\ClientReportTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ClientReportTemplateController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        $templateName = $request->input('template_name');

        if (is_string($templateName)) {
            $request->merge(['template_name' => trim($templateName)]);
        }

        $reportBrandName = $request->input('report_brand_name');

        if (is_string($reportBrandName)) {
            $request->merge(['report_brand_name' => trim($reportBrandName)]);
        }

        $validator = Validator::make($request->all(), [
            'template_name' => ['required', 'string', 'max:80'],
            'report_brand_name' => ['nullable', 'string', 'max:80'],
            'template_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('client_report_templates', 'id')->where('client_id', $client->getKey()),
            ],
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
            'template_name.required' => '請輸入樣板名稱。',
            'template_name.string' => '樣板名稱格式無效。',
            'template_name.max' => '樣板名稱不可超過 80 個字元。',
            'report_brand_name.string' => '報表品牌名稱格式無效。',
            'report_brand_name.max' => '報表品牌名稱不可超過 80 個字元。',
            'template_id.required' => '樣板識別格式無效。',
            'template_id.integer' => '樣板識別格式無效。',
            'template_id.exists' => '樣板不存在或不屬於此客戶。',
            'compare_previous_period.boolean' => '前期比較選項格式無效。',
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
        $templateAttributes = [
            'name' => $validated['template_name'],
            'report_brand_name' => $validated['report_brand_name'] ?? null,
            'campaign_target_ids' => array_map('intval', $validated['target_ids']),
            'report_sections' => array_values($validated['report_sections'] ?? ClientReportShare::DEFAULT_REPORT_SECTIONS),
            'compare_previous_period' => in_array($validated['compare_previous_period'] ?? false, [true, 1, '1'], true),
        ];
        $templateId = $validated['template_id'] ?? null;

        if ($templateId === null) {
            $template = $client->reportTemplates()->create($templateAttributes);
            $status = '報表樣板已儲存；建立分享時請選擇日期與有效期限。';
        } else {
            $template = $client->reportTemplates()->findOrFail((int) $templateId);
            $template->fill($templateAttributes)->save();
            $status = '報表樣板已更新；建立分享時請選擇日期與有效期限。';
        }

        return redirect()
            ->route('clients.report-shares.index', ['client' => $client, 'template_id' => $template->getKey()])
            ->with('status', $status);
    }

    public function destroy(Client $client, ClientReportTemplate $reportTemplate): RedirectResponse
    {
        $reportTemplate->delete();

        return redirect()
            ->route('clients.report-shares.index', $client)
            ->with('status', '報表樣板已刪除。');
    }
}
