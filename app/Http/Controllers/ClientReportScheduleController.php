<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientReportSchedule;
use App\Services\NotificationEmailRecipientParser;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ClientReportScheduleController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        $recipients = NotificationEmailRecipientParser::parse(
            is_string($request->input('recipients')) ? $request->input('recipients') : null,
        );

        $validator = Validator::make([
            'template_id' => $request->input('template_id'),
            'frequency' => $request->input('frequency'),
            'recipients' => $recipients,
        ], [
            'template_id' => [
                'required',
                'integer',
                Rule::exists('client_report_templates', 'id')->where('client_id', $client->getKey()),
            ],
            'frequency' => ['required', 'string', Rule::in(array_keys(ClientReportSchedule::FREQUENCY_LABELS))],
            'recipients' => ['required', 'array', 'min:1', 'max:'.ClientReportSchedule::MAX_RECIPIENTS],
            'recipients.*' => ['required', 'string', 'email', 'max:254'],
        ], [
            'template_id.required' => '請選擇要寄送的報表樣板。',
            'template_id.integer' => '報表樣板選取格式無效。',
            'template_id.exists' => '報表樣板不存在或不屬於此客戶。',
            'frequency.required' => '請選擇寄送頻率。',
            'frequency.in' => '請選擇每週或每月寄送。',
            'recipients.required' => '請至少輸入一個收件信箱。',
            'recipients.min' => '請至少輸入一個收件信箱。',
            'recipients.max' => '最多可設定 '.ClientReportSchedule::MAX_RECIPIENTS.' 個收件信箱。',
            'recipients.*.email' => '每個收件信箱都必須是有效電子郵件地址。',
            'recipients.*.max' => '單一收件信箱不得超過 254 個字元。',
        ]);

        if ($validator->fails()) {
            return redirect()
                ->route('clients.report-shares.index', $client)
                ->withErrors($validator, 'reportSchedule')
                ->withInput($request->only(['template_id', 'frequency', 'recipients']));
        }

        $validated = $validator->validated();

        $client->reportSchedules()->create([
            'client_report_template_id' => (int) $validated['template_id'],
            'frequency' => $validated['frequency'],
            'recipients' => implode("\n", $validated['recipients']),
            'next_run_on' => ClientReportSchedule::nextRunAfter($validated['frequency'], CarbonImmutable::now())->toDateString(),
        ]);

        return redirect()
            ->route('clients.report-shares.index', $client)
            ->with('status', '已建立自動寄送排程。');
    }

    public function update(Request $request, Client $client, ClientReportSchedule $reportSchedule): RedirectResponse
    {
        $request->validate([
            'paused' => ['required', 'boolean'],
        ]);

        if ($request->boolean('paused')) {
            $reportSchedule->forceFill(['paused_at' => $reportSchedule->paused_at ?? now()])->save();
            $status = '已暫停自動寄送排程。';
        } else {
            $reportSchedule->forceFill([
                'paused_at' => null,
                'consecutive_failures' => 0,
                'next_run_on' => ClientReportSchedule::nextRunAfter($reportSchedule->frequency, CarbonImmutable::now())->toDateString(),
            ])->save();
            $status = '已恢復自動寄送排程。';
        }

        return redirect()
            ->route('clients.report-shares.index', $client)
            ->with('status', $status);
    }

    public function destroy(Client $client, ClientReportSchedule $reportSchedule): RedirectResponse
    {
        $reportSchedule->delete();

        return redirect()
            ->route('clients.report-shares.index', $client)
            ->with('status', '已刪除自動寄送排程。');
    }
}
