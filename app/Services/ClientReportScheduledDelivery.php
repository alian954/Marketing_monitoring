<?php

namespace App\Services;

use App\Mail\ClientReportDelivered;
use App\Models\Client;
use App\Models\ClientReportSchedule;
use App\Models\ClientReportShare;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class ClientReportScheduledDelivery
{
    public const OUTCOME_SENT = 'sent';

    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_FAILED = 'failed';

    /**
     * After this many failed days in a row the schedule moves on to the next period instead of retrying.
     */
    public const MAX_CONSECUTIVE_FAILURES = 3;

    public function __construct(
        private ClientReportSnapshotRecorder $snapshotRecorder,
        private ProductEntitlementService $entitlements,
    ) {}

    /**
     * @return self::OUTCOME_*
     */
    public function deliver(ClientReportSchedule $schedule, CarbonImmutable $today): string
    {
        $today = $today->setTimezone(ClientReportSchedule::TIMEZONE)->startOfDay();
        $client = Client::withoutGlobalScopes()->find($schedule->client_id);
        $template = $schedule->template;

        if ($client === null || $template === null) {
            return self::OUTCOME_SKIPPED;
        }

        $ownerUserId = $client->user_id === null ? null : (int) $client->user_id;

        if (! Organization::isAccessibleForOwner($ownerUserId, $today->toDateString())
            || ($ownerUserId !== null && ! $this->entitlements->ownerHasFeature($ownerUserId, 'marketing-insights', 'marketing.reports'))) {
            return self::OUTCOME_SKIPPED;
        }

        $period = ClientReportSchedule::periodForDeliveryOn($schedule->frequency, $today);

        if ($schedule->last_delivered_period_ends_on?->toDateString() === $period['to']->toDateString()) {
            $schedule->forceFill(['next_run_on' => ClientReportSchedule::nextRunAfter($schedule->frequency, $today)])->save();

            return self::OUTCOME_SKIPPED;
        }

        $targetIds = $client->socialEngagementTargets()
            ->withoutGlobalScopes()
            ->whereIn('id', is_array($template->campaign_target_ids) ? $template->campaign_target_ids : [])
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($targetIds === []) {
            return $this->recordFailure($schedule, $today, null, '樣板沒有可寄送的活動，請更新樣板。');
        }

        $periodLabel = $period['from']->format('Y/n/j').'–'.$period['to']->format('Y/n/j');
        $reportTitle = $schedule->frequency === ClientReportSchedule::FREQUENCY_MONTHLY ? '月報' : '週報';
        $share = null;

        try {
            $token = bin2hex(random_bytes(32));
            $share = $client->reportShares()->create([
                'name' => Str::limit($template->name.'｜'.$reportTitle.' '.$periodLabel, 80, ''),
                'report_brand_name' => $template->report_brand_name,
                'token_hash' => hash('sha256', $token),
                'campaign_target_ids' => $targetIds,
                'report_sections' => $template->selectedReportSections(),
                'report_starts_on' => $period['from']->toDateString(),
                'report_ends_on' => $period['to']->toDateString(),
                'compare_previous_period' => $template->compare_previous_period,
                'expires_at' => now()->addDays($schedule->frequency === ClientReportSchedule::FREQUENCY_MONTHLY ? 90 : 30),
            ]);
            $snapshot = $this->snapshotRecorder->record($share, null);

            Mail::to($schedule->recipientList())->send(new ClientReportDelivered(
                brandName: $template->report_brand_name ?? (string) config('app.name'),
                clientName: $client->name,
                reportTitle: $reportTitle,
                periodLabel: $periodLabel,
                reportUrl: route('client-report-shares.public', ['token' => $token]),
                expiresAt: $share->expires_at,
                csvContent: (string) $snapshot->csv_content,
                csvFilename: 'client-report-'.$period['from']->toDateString().'-to-'.$period['to']->toDateString().'.csv',
            ));
        } catch (Throwable $exception) {
            Log::warning('Scheduled client report could not be delivered.', [
                'client_report_schedule_id' => $schedule->getKey(),
                'exception' => $exception::class,
            ]);

            $share?->forceFill(['revoked_at' => now()])->save();

            return $this->recordFailure($schedule, $today, $share, '報表產生或寄送失敗，系統將於明天重試。');
        }

        $schedule->forceFill([
            'last_attempted_at' => now(),
            'last_delivered_at' => now(),
            'last_delivered_period_ends_on' => $period['to']->toDateString(),
            'last_report_share_id' => $share->getKey(),
            'last_failure_reason' => null,
            'consecutive_failures' => 0,
            'next_run_on' => ClientReportSchedule::nextRunAfter($schedule->frequency, $today),
        ])->save();

        return self::OUTCOME_SENT;
    }

    private function recordFailure(
        ClientReportSchedule $schedule,
        CarbonImmutable $today,
        ?ClientReportShare $revokedShare,
        string $reason,
    ): string {
        $consecutiveFailures = $schedule->consecutive_failures + 1;
        $givesUpOnPeriod = $consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES;

        $schedule->forceFill([
            'last_attempted_at' => now(),
            'last_failure_reason' => $givesUpOnPeriod
                ? Str::replaceLast('系統將於明天重試。', '已連續失敗，改於下一期再寄送。', $reason)
                : $reason,
            'consecutive_failures' => $givesUpOnPeriod ? 0 : $consecutiveFailures,
            'next_run_on' => $givesUpOnPeriod
                ? ClientReportSchedule::nextRunAfter($schedule->frequency, $today)
                : $today->addDay()->toDateString(),
        ])->save();

        return self::OUTCOME_FAILED;
    }
}
