<?php

namespace App\Services;

use App\Jobs\SendNotificationOutbox;
use App\Models\Client;
use App\Models\LineNotificationTarget;
use App\Models\MarketingAlertEvent;
use App\Models\MarketingAlertRule;
use App\Models\NotificationOutbox;
use App\Models\Organization;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class MarketingAlertEvaluator
{
    public const TIMEZONE = 'Asia/Taipei';

    public const STALE_AFTER_HOURS = 36;

    private const HISTORY_DAYS = 7;

    private const MIN_HISTORY_DAYS = 3;

    private const MIN_PRIOR_CONVERSIONS = 3;

    /**
     * Normalized daily-row keys for each paid media source.
     *
     * @var array<string, array{conversions: string, roas: string}>
     */
    private const SOURCE_KEYS = [
        'meta_marketing' => ['conversions' => 'omni_purchase_count', 'roas' => 'purchase_roas'],
        'google_ads' => ['conversions' => 'conversions', 'roas' => 'conversion_value_roas'],
    ];

    public function __construct(
        private CampaignDailyMarketingPerformanceReport $dailyPerformanceReport,
        private ProductEntitlementService $entitlements,
    ) {}

    /**
     * Evaluate every active rule of a client and queue notifications for newly triggered alerts.
     *
     * @return int The number of new alert events.
     */
    public function evaluateClient(Client $client, CarbonImmutable $now): int
    {
        $rules = $client->marketingAlertRules()->whereNull('paused_at')->get();
        $ownerUserId = $client->user_id === null ? null : (int) $client->user_id;
        $today = $now->setTimezone(self::TIMEZONE)->startOfDay();

        if ($rules->isEmpty()
            || ! Organization::isAccessibleForOwner($ownerUserId, $today->toDateString())
            || ($ownerUserId !== null && ! $this->entitlements->ownerHasFeature($ownerUserId, 'marketing-insights', 'marketing.reports'))) {
            return 0;
        }

        $yesterday = $today->subDay();
        $signals = $this->signalsForActiveTargets($client, $yesterday);
        $newEventCount = 0;

        foreach ($rules as $rule) {
            $events = [];

            foreach ($signals as $signal) {
                foreach ($this->candidates($rule, $signal, $yesterday, $now) as $candidate) {
                    $event = $this->recordOnce($rule, $candidate);

                    if ($event !== null) {
                        $events[] = $event;
                    }
                }
            }

            if ($events !== []) {
                $this->queueNotifications($client, $rule, $events);
                $rule->forceFill(['last_triggered_at' => now()])->save();
                $newEventCount += count($events);
            }
        }

        return $newEventCount;
    }

    /**
     * @return list<array{target: SocialEngagementTarget, sources: array<string, array{label: string, mapped: bool, report: SocialEngagementTargetMetaMarketingReport|SocialEngagementTargetGoogleAdsReport|null, currency_code: string|null, rows: array<string, array<string, mixed>>}>}>
     */
    private function signalsForActiveTargets(Client $client, CarbonImmutable $yesterday): array
    {
        /** @var Collection<int, SocialEngagementTarget> $targets */
        $targets = SocialEngagementTarget::withoutGlobalScopes()
            ->where('client_id', $client->getKey())
            ->whereNotNull('starts_on')
            ->whereDate('starts_on', '<=', $yesterday->toDateString())
            ->where(static function ($query) use ($yesterday): void {
                $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $yesterday->toDateString());
            })
            ->orderBy('id')
            ->get();
        $signals = [];

        foreach ($targets as $target) {
            $metaReport = $target->meta_campaign_id === null
                ? null
                : SocialEngagementTargetMetaMarketingReport::query()
                    ->where('social_engagement_target_id', $target->getKey())
                    ->where('campaign_id', $target->meta_campaign_id)
                    ->latest('synced_at')
                    ->first();
            $googleAdsReport = $target->google_ads_campaign_id === null
                ? null
                : SocialEngagementTargetGoogleAdsReport::query()
                    ->where('social_engagement_target_id', $target->getKey())
                    ->where('campaign_id', $target->google_ads_campaign_id)
                    ->latest('synced_at')
                    ->first();
            $daily = $this->dailyPerformanceReport->build(null, $metaReport, $googleAdsReport);
            $sources = [];

            foreach ([
                'meta_marketing' => [$target->meta_campaign_id !== null, $metaReport],
                'google_ads' => [$target->google_ads_campaign_id !== null, $googleAdsReport],
            ] as $source => [$mapped, $report]) {
                $sources[$source] = [
                    'label' => $daily[$source]['label'],
                    'mapped' => $mapped,
                    'report' => $report,
                    'currency_code' => $daily[$source]['currency_code'],
                    'rows' => collect($daily[$source]['rows'])->keyBy('date')->all(),
                ];
            }

            $signals[] = ['target' => $target, 'sources' => $sources];
        }

        return $signals;
    }

    /**
     * @param  array{target: SocialEngagementTarget, sources: array<string, array{label: string, mapped: bool, report: mixed, currency_code: string|null, rows: array<string, array<string, mixed>>}>}  $signal
     * @return list<array{target: SocialEngagementTarget, source: string, observed_on: string, observed_value: float|null, currency_code: string|null, message: string}>
     */
    private function candidates(MarketingAlertRule $rule, array $signal, CarbonImmutable $yesterday, CarbonImmutable $now): array
    {
        $target = $signal['target'];

        if ($rule->metric === MarketingAlertRule::METRIC_BUDGET_OVERPACE) {
            $candidate = $this->budgetOverpaceCandidate($rule, $signal, $yesterday);

            return $candidate === null ? [] : [$candidate];
        }

        $candidates = [];

        foreach ($signal['sources'] as $source => $sourceSignal) {
            if (! $sourceSignal['mapped']) {
                continue;
            }

            $prefix = $target->tag.'｜'.$sourceSignal['label'];
            $currencyCode = $sourceSignal['currency_code'];

            if ($rule->metric === MarketingAlertRule::METRIC_SYNC_STALE) {
                $syncedAt = $sourceSignal['report']?->synced_at;
                $neverSynced = $syncedAt === null;

                if ($neverSynced && $target->starts_on->toImmutable()->greaterThan($now->subHours(self::STALE_AFTER_HOURS))) {
                    continue;
                }

                if ($neverSynced || $syncedAt->lessThanOrEqualTo($now->subHours(self::STALE_AFTER_HOURS))) {
                    $candidates[] = $this->candidate(
                        $target,
                        $source,
                        $neverSynced ? $target->starts_on->toDateString() : $syncedAt->setTimezone(self::TIMEZONE)->toDateString(),
                        null,
                        null,
                        $prefix.($neverSynced
                            ? ' 已綁定活動但尚未同步到數據，請確認授權與廣告帳戶對應。'
                            : ' 最後同步於 '.$syncedAt->setTimezone(self::TIMEZONE)->format('Y/n/j H:i').'，已超過 '.self::STALE_AFTER_HOURS.' 小時未更新。'),
                    );
                }

                continue;
            }

            $row = $sourceSignal['rows'][$yesterday->toDateString()] ?? null;

            if ($row === null) {
                continue;
            }

            $spend = $this->number($row['spend_amount'] ?? null);
            $threshold = $rule->threshold === null ? null : (float) $rule->threshold;
            $history = $this->historyRows($sourceSignal['rows'], $yesterday);
            $dateLabel = $yesterday->format('n/j');

            if ($rule->metric === MarketingAlertRule::METRIC_SPEND_SPIKE && $spend !== null && $spend > 0 && $threshold !== null) {
                $historySpend = array_values(array_filter(
                    array_map(fn (array $historyRow): ?float => $this->number($historyRow['spend_amount'] ?? null), $history),
                    static fn (?float $value): bool => $value !== null,
                ));

                if (count($historySpend) >= self::MIN_HISTORY_DAYS) {
                    $average = array_sum($historySpend) / count($historySpend);

                    if ($average > 0 && $spend > $average * (1 + $threshold / 100)) {
                        $candidates[] = $this->candidate($target, $source, $yesterday->toDateString(), $spend, $currencyCode, sprintf(
                            '%s %s 花費 %s，比前 %d 日平均 %s 高 %s%%。',
                            $prefix,
                            $dateLabel,
                            $this->money($spend, $currencyCode),
                            count($historySpend),
                            $this->money($average, $currencyCode),
                            number_format(($spend / $average - 1) * 100, 0),
                        ));
                    }
                }
            }

            if ($rule->metric === MarketingAlertRule::METRIC_CPA_ABOVE && $threshold !== null) {
                $costPerAction = $this->number($row['cost_per_action'] ?? null);

                if ($costPerAction !== null && $costPerAction > $threshold) {
                    $candidates[] = $this->candidate($target, $source, $yesterday->toDateString(), $costPerAction, $currencyCode, sprintf(
                        '%s %s CPA %s，高於門檻 %s。',
                        $prefix,
                        $dateLabel,
                        $this->money($costPerAction, $currencyCode),
                        $this->money($threshold, $currencyCode),
                    ));
                }
            }

            if ($rule->metric === MarketingAlertRule::METRIC_ROAS_BELOW && $threshold !== null && $spend !== null && $spend > 0) {
                $roas = $this->number($row[self::SOURCE_KEYS[$source]['roas']] ?? null);

                if ($roas !== null && $roas < $threshold) {
                    $candidates[] = $this->candidate($target, $source, $yesterday->toDateString(), $roas, null, sprintf(
                        '%s %s ROAS %s 倍，低於門檻 %s 倍（花費 %s）。',
                        $prefix,
                        $dateLabel,
                        number_format($roas, 2),
                        number_format($threshold, 2),
                        $this->money($spend, $currencyCode),
                    ));
                }
            }

            if ($rule->metric === MarketingAlertRule::METRIC_CONVERSIONS_STOPPED && $spend !== null && $spend > 0) {
                $conversionKey = self::SOURCE_KEYS[$source]['conversions'];
                $conversions = $this->number($row[$conversionKey] ?? null) ?? 0.0;
                $priorConversions = array_sum(array_map(
                    fn (array $historyRow): float => $this->number($historyRow[$conversionKey] ?? null) ?? 0.0,
                    $history,
                ));

                if ($conversions <= 0 && $priorConversions >= self::MIN_PRIOR_CONVERSIONS) {
                    $candidates[] = $this->candidate($target, $source, $yesterday->toDateString(), 0.0, null, sprintf(
                        '%s %s 花費 %s 但沒有轉換，前 %d 日共有 %s 次轉換，請檢查追蹤碼與到達頁。',
                        $prefix,
                        $dateLabel,
                        $this->money($spend, $currencyCode),
                        self::HISTORY_DAYS,
                        number_format($priorConversions, 0),
                    ));
                }
            }
        }

        return $candidates;
    }

    /**
     * @param  array{target: SocialEngagementTarget, sources: array<string, array{label: string, mapped: bool, report: mixed, currency_code: string|null, rows: array<string, array<string, mixed>>}>}  $signal
     * @return array{target: SocialEngagementTarget, source: string, observed_on: string, observed_value: float|null, currency_code: string|null, message: string}|null
     */
    private function budgetOverpaceCandidate(MarketingAlertRule $rule, array $signal, CarbonImmutable $yesterday): ?array
    {
        $target = $signal['target'];
        $budget = $this->number($target->campaign_budget_amount);
        $budgetCurrency = is_string($target->campaign_budget_currency) ? strtoupper($target->campaign_budget_currency) : null;

        if ($budget === null || $budget <= 0 || $budgetCurrency === null || $target->ends_on === null || $rule->threshold === null) {
            return null;
        }

        $startsOn = $target->starts_on->toDateString();
        $spend = 0.0;
        $hasSpendSource = false;

        foreach ($signal['sources'] as $sourceSignal) {
            if (! $sourceSignal['mapped'] || $sourceSignal['currency_code'] !== $budgetCurrency) {
                continue;
            }

            $hasSpendSource = true;

            foreach ($sourceSignal['rows'] as $date => $row) {
                if ($date >= $startsOn && $date <= $yesterday->toDateString()) {
                    $spend += $this->number($row['spend_amount'] ?? null) ?? 0.0;
                }
            }
        }

        if (! $hasSpendSource || $spend <= 0) {
            return null;
        }

        $campaignStart = CarbonImmutable::createFromFormat('!Y-m-d', $startsOn, self::TIMEZONE);
        $campaignDays = (int) $campaignStart->diffInDays(CarbonImmutable::createFromFormat('!Y-m-d', $target->ends_on->toDateString(), self::TIMEZONE)) + 1;
        $elapsedDays = min($campaignDays, (int) $campaignStart->diffInDays($yesterday) + 1);
        $expectedSpend = $budget * $elapsedDays / $campaignDays;

        if ($spend < $budget && $spend <= $expectedSpend * (1 + (float) $rule->threshold / 100)) {
            return null;
        }

        return $this->candidate($target, 'budget', $target->ends_on->toDateString(), $spend, $budgetCurrency, sprintf(
            '%s 累計花費 %s，已達預算 %s 的 %s%%，時間進度僅 %s%%（%d/%d 天）。',
            $target->tag,
            $this->money($spend, $budgetCurrency),
            $this->money($budget, $budgetCurrency),
            number_format($spend / $budget * 100, 0),
            number_format($elapsedDays / $campaignDays * 100, 0),
            $elapsedDays,
            $campaignDays,
        ));
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function historyRows(array $rows, CarbonImmutable $yesterday): array
    {
        $history = [];

        for ($daysBack = 1; $daysBack <= self::HISTORY_DAYS; $daysBack++) {
            $row = $rows[$yesterday->subDays($daysBack)->toDateString()] ?? null;

            if ($row !== null) {
                $history[] = $row;
            }
        }

        return $history;
    }

    /**
     * @return array{target: SocialEngagementTarget, source: string, observed_on: string, observed_value: float|null, currency_code: string|null, message: string}
     */
    private function candidate(
        SocialEngagementTarget $target,
        string $source,
        string $observedOn,
        ?float $observedValue,
        ?string $currencyCode,
        string $message,
    ): array {
        return [
            'target' => $target,
            'source' => $source,
            'observed_on' => $observedOn,
            'observed_value' => $observedValue,
            'currency_code' => $currencyCode,
            'message' => mb_substr(trim(preg_replace('/[\r\n]+/u', ' ', $message) ?? $message), 0, 500),
        ];
    }

    /**
     * @param  array{target: SocialEngagementTarget, source: string, observed_on: string, observed_value: float|null, currency_code: string|null, message: string}  $candidate
     */
    private function recordOnce(MarketingAlertRule $rule, array $candidate): ?MarketingAlertEvent
    {
        $alreadyRecorded = $rule->events()
            ->where('social_engagement_target_id', $candidate['target']->getKey())
            ->where('source', $candidate['source'])
            ->whereDate('observed_on', $candidate['observed_on'])
            ->exists();

        if ($alreadyRecorded) {
            return null;
        }

        try {
            return $rule->events()->create([
                'social_engagement_target_id' => $candidate['target']->getKey(),
                'source' => $candidate['source'],
                'observed_on' => $candidate['observed_on'],
                'observed_value' => $candidate['observed_value'],
                'currency_code' => $candidate['currency_code'],
                'message' => $candidate['message'],
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * @param  list<MarketingAlertEvent>  $events
     */
    private function queueNotifications(Client $client, MarketingAlertRule $rule, array $events): void
    {
        $destinations = array_map(
            static fn (string $recipient): array => [NotificationOutbox::CHANNEL_EMAIL, $recipient],
            $rule->recipientList(),
        );

        if (is_string($rule->webhook_url) && $rule->webhook_url !== '' && is_string($rule->webhook_channel)) {
            $destinations[] = [$rule->webhook_channel, $rule->webhook_url];
        }

        $lineTarget = $rule->line_notification_target_id === null
            ? null
            : LineNotificationTarget::query()
                ->withoutGlobalScopes()
                ->active()
                ->whereKey($rule->line_notification_target_id)
                ->where('workspace_id', $client->workspace_id ?? Workspace::idForOwnerUserId((int) $client->user_id))
                ->first();

        if ($lineTarget !== null) {
            $destinations[] = [NotificationOutbox::CHANNEL_LINE, $lineTarget->target_id];
        }

        if ($destinations === []) {
            return;
        }

        $eventIds = implode(',', array_map(static fn (MarketingAlertEvent $event): int => (int) $event->getKey(), $events));
        $subject = '['.config('app.name').'] '.$client->name.'｜'.$rule->label().'（'.count($events).' 則）';
        $body = $client->name.' 觸發行銷數據警示：'.$rule->label()."\n\n"
            .implode("\n", array_map(
                static fn (MarketingAlertEvent $event): string => '・'.$event->message,
                $events,
            ))
            ."\n\n查看活動成效：".route('clients.marketing-alerts.index', $client);

        DB::transaction(function () use ($body, $client, $destinations, $eventIds, $rule, $subject): void {
            foreach ($destinations as [$channel, $recipient]) {
                $outbox = NotificationOutbox::query()->create([
                    'user_id' => $client->user_id,
                    'incident_id' => null,
                    'monitor_id' => null,
                    'event_key' => hash('sha256', implode(':', [
                        NotificationOutbox::EVENT_TYPE_MARKETING_ALERT,
                        $rule->getKey(),
                        $eventIds,
                        $channel,
                        hash('sha256', mb_strtolower($recipient)),
                    ])),
                    'channel' => $channel,
                    'recipient' => $recipient,
                    'event_type' => NotificationOutbox::EVENT_TYPE_MARKETING_ALERT,
                    'status' => 'pending',
                    'subject' => $subject,
                    'body' => $body,
                ]);

                SendNotificationOutbox::dispatch($outbox->id)->afterCommit();
            }
        });
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private function money(float $amount, ?string $currencyCode): string
    {
        return trim(($currencyCode ?? '').' '.number_format($amount, 2));
    }
}
