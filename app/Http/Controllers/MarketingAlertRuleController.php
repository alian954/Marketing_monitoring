<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\LineNotificationTarget;
use App\Models\MarketingAlertEvent;
use App\Models\MarketingAlertRule;
use App\Models\NotificationOutbox;
use App\Services\DiscordWebhookUrlPolicy;
use App\Services\NotificationEmailRecipientParser;
use App\Services\SlackWebhookUrlPolicy;
use App\Services\TeamsWebhookUrlPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MarketingAlertRuleController extends Controller
{
    private const RECENT_EVENT_LIMIT = 30;

    private const TIMEZONE = 'Asia/Taipei';

    private const MAX_RECIPIENTS = 10;

    /**
     * Days a handled alert can keep the same rule, campaign and source quiet.
     *
     * @var list<int>
     */
    public const MUTE_DAY_OPTIONS = [7, 14, 30];

    public function index(Client $client): Response
    {
        $rules = $client->marketingAlertRules()->with('lineNotificationTarget')->latest('id')->get();
        $lineTargets = LineNotificationTarget::query()->active()->orderBy('display_name')->get();
        $recentEvents = MarketingAlertEvent::query()
            ->whereIn('marketing_alert_rule_id', $rules->modelKeys())
            ->with(['rule:id,metric', 'target:id,tag', 'acknowledgedBy:id,name'])
            ->latest('id')
            ->limit(self::RECENT_EVENT_LIMIT)
            ->get();
        $unacknowledgedEventCount = MarketingAlertEvent::query()
            ->whereIn('marketing_alert_rule_id', $rules->modelKeys())
            ->unacknowledged()
            ->count();

        return response()
            ->view('marketing-alerts.index', [
                'client' => $client,
                'rules' => $rules,
                'recentEvents' => $recentEvents,
                'unacknowledgedEventCount' => $unacknowledgedEventCount,
                'muteDayOptions' => self::MUTE_DAY_OPTIONS,
                'lineTargets' => $lineTargets,
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $metric = $request->input('metric');
        $recipients = NotificationEmailRecipientParser::parse(
            is_string($request->input('recipients')) ? $request->input('recipients') : null,
        );
        $webhookUrl = is_string($request->input('webhook_url')) ? trim($request->input('webhook_url')) : '';
        $lineTargetInput = $request->input('line_notification_target_id');
        $lineTarget = is_numeric($lineTargetInput)
            ? LineNotificationTarget::query()->active()->find((int) $lineTargetInput)
            : null;
        $lineTargetRequested = $lineTargetInput !== null && $lineTargetInput !== '';
        $needsThreshold = is_string($metric) && (MarketingAlertRule::METRICS[$metric]['threshold_label'] ?? null) !== null;
        $threshold = $request->input('threshold');

        if ($needsThreshold && ($threshold === null || $threshold === '')) {
            $threshold = MarketingAlertRule::METRICS[$metric]['default_threshold'];
        }

        $validator = Validator::make([
            'metric' => $metric,
            'threshold' => $threshold,
            'recipients' => $recipients,
            'webhook_url' => $webhookUrl === '' ? null : $webhookUrl,
        ], [
            'metric' => ['required', 'string', Rule::in(array_keys(MarketingAlertRule::METRICS))],
            'threshold' => $needsThreshold
                ? ['required', 'numeric', 'gt:0', 'max:99999999']
                : ['nullable'],
            'recipients' => ['array', 'max:'.self::MAX_RECIPIENTS],
            'recipients.*' => ['required', 'string', 'email', 'max:254'],
            'webhook_url' => ['nullable', 'string', 'max:2048'],
        ], [
            'metric.required' => '請選擇警示類型。',
            'metric.in' => '所選警示類型無效。',
            'threshold.required' => '請輸入警示門檻。',
            'threshold.numeric' => '警示門檻必須是數字。',
            'threshold.gt' => '警示門檻必須大於 0。',
            'threshold.max' => '警示門檻過大。',
            'recipients.max' => '最多可設定 '.self::MAX_RECIPIENTS.' 個收件信箱。',
            'recipients.*.email' => '每個收件信箱都必須是有效電子郵件地址。',
            'recipients.*.max' => '單一收件信箱不得超過 254 個字元。',
            'webhook_url.max' => 'Webhook 網址過長。',
        ]);
        $webhookChannel = null;
        $validator->after(function ($validator) use ($recipients, $webhookUrl, $lineTarget, $lineTargetRequested, &$webhookChannel): void {
            if ($lineTargetRequested && $lineTarget === null) {
                $validator->errors()->add('line_notification_target_id', '所選 LINE 推播對象無效或已離開。');
            }

            if ($webhookUrl !== '') {
                $webhookChannel = match (true) {
                    SlackWebhookUrlPolicy::isAllowed($webhookUrl) => NotificationOutbox::CHANNEL_SLACK,
                    TeamsWebhookUrlPolicy::isAllowed($webhookUrl) => NotificationOutbox::CHANNEL_TEAMS,
                    DiscordWebhookUrlPolicy::isAllowed($webhookUrl) => NotificationOutbox::CHANNEL_DISCORD,
                    default => null,
                };

                if ($webhookChannel === null) {
                    $validator->errors()->add('webhook_url', '只支援 Slack、Microsoft Teams 或 Discord 的 HTTPS Webhook 網址。');
                }
            }

            if ($recipients === [] && $webhookUrl === '' && ! $lineTargetRequested) {
                $validator->errors()->add('recipients', '請至少設定一個收件信箱、Webhook 或 LINE 推播對象。');
            }
        });

        if ($validator->fails()) {
            return redirect()
                ->route('clients.marketing-alerts.index', $client)
                ->withErrors($validator)
                ->withInput($request->only(['metric', 'threshold', 'recipients', 'line_notification_target_id']));
        }

        $client->marketingAlertRules()->create([
            'metric' => $metric,
            'threshold' => $needsThreshold ? (string) $threshold : null,
            'recipients' => $recipients === [] ? null : implode("\n", $recipients),
            'webhook_url' => $webhookUrl === '' ? null : $webhookUrl,
            'webhook_channel' => $webhookChannel,
            'line_notification_target_id' => $lineTarget?->getKey(),
        ]);

        return redirect()
            ->route('clients.marketing-alerts.index', $client)
            ->with('status', '已建立行銷警示規則。');
    }

    public function update(Request $request, Client $client, MarketingAlertRule $marketingAlertRule): RedirectResponse
    {
        $request->validate(['paused' => ['required', 'boolean']]);

        $marketingAlertRule->forceFill([
            'paused_at' => $request->boolean('paused') ? ($marketingAlertRule->paused_at ?? now()) : null,
        ])->save();

        return redirect()
            ->route('clients.marketing-alerts.index', $client)
            ->with('status', $request->boolean('paused') ? '已暫停警示規則。' : '已恢復警示規則。');
    }

    public function destroy(Client $client, MarketingAlertRule $marketingAlertRule): RedirectResponse
    {
        $marketingAlertRule->delete();

        return redirect()
            ->route('clients.marketing-alerts.index', $client)
            ->with('status', '已刪除警示規則。');
    }

    /**
     * Mark one alert as handled, optionally muting the same campaign and source, or reopen it.
     */
    public function updateEvent(Request $request, Client $client, MarketingAlertEvent $marketingAlertEvent): RedirectResponse
    {
        $validated = $request->validate([
            'handled' => ['required', 'boolean'],
            'mute_days' => ['nullable', 'integer', Rule::in(self::MUTE_DAY_OPTIONS)],
        ], [
            'mute_days.in' => '所選靜音天數無效。',
        ]);

        if (! $request->boolean('handled')) {
            $marketingAlertEvent->forceFill([
                'acknowledged_at' => null,
                'acknowledged_by_user_id' => null,
                'muted_until' => null,
            ])->save();

            return redirect()
                ->route('clients.marketing-alerts.index', $client)
                ->with('status', '已將警示改回待處理。');
        }

        $muteDays = isset($validated['mute_days']) ? (int) $validated['mute_days'] : null;
        $mutedUntil = $muteDays === null
            ? null
            : CarbonImmutable::now(self::TIMEZONE)->startOfDay()->addDays($muteDays);

        $marketingAlertEvent->forceFill([
            'acknowledged_at' => $marketingAlertEvent->acknowledged_at ?? now(),
            'acknowledged_by_user_id' => $request->user()?->getKey(),
            'muted_until' => $mutedUntil?->toDateString(),
        ])->save();

        return redirect()
            ->route('clients.marketing-alerts.index', $client)
            ->with('status', $mutedUntil === null
                ? '已標示為已處理。'
                : '已標示為已處理，此活動的同類警示在 '.$mutedUntil->format('Y/n/j').' 前不再通知。');
    }

    /**
     * Mark every pending alert of the client as handled.
     */
    public function acknowledgeAllEvents(Request $request, Client $client): RedirectResponse
    {
        $handledCount = $client->marketingAlertEvents()
            ->whereNull('marketing_alert_events.acknowledged_at')
            ->update([
                'marketing_alert_events.acknowledged_at' => now(),
                'marketing_alert_events.acknowledged_by_user_id' => $request->user()?->getKey(),
                'marketing_alert_events.updated_at' => now(),
            ]);

        return redirect()
            ->route('clients.marketing-alerts.index', $client)
            ->with('status', '已將 '.number_format($handledCount).' 則警示標示為已處理。');
    }
}
