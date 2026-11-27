<?php

namespace App\Console\Commands;

use App\Models\ClientReportSchedule;
use App\Services\ClientReportScheduledDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('client-reports:send-scheduled')]
#[Description('依排程產生客戶報表快照並寄送給客戶窗口。')]
class SendScheduledClientReports extends Command
{
    public function handle(ClientReportScheduledDelivery $delivery): int
    {
        $today = CarbonImmutable::now(ClientReportSchedule::TIMEZONE)->startOfDay();
        $counts = [
            ClientReportScheduledDelivery::OUTCOME_SENT => 0,
            ClientReportScheduledDelivery::OUTCOME_SKIPPED => 0,
            ClientReportScheduledDelivery::OUTCOME_FAILED => 0,
        ];

        ClientReportSchedule::query()
            ->with('template')
            ->whereNull('paused_at')
            ->whereDate('next_run_on', '<=', $today->toDateString())
            ->chunkById(50, function (Collection $schedules) use ($delivery, $today, &$counts): void {
                foreach ($schedules as $schedule) {
                    $counts[$delivery->deliver($schedule, $today)]++;
                }
            });

        $this->info(sprintf(
            '已寄送 %d 份排程報表，略過 %d 份，失敗 %d 份。',
            $counts[ClientReportScheduledDelivery::OUTCOME_SENT],
            $counts[ClientReportScheduledDelivery::OUTCOME_SKIPPED],
            $counts[ClientReportScheduledDelivery::OUTCOME_FAILED],
        ));

        return $counts[ClientReportScheduledDelivery::OUTCOME_FAILED] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
