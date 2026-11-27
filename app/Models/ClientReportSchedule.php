<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ClientReportScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['client_report_template_id', 'frequency', 'recipients', 'next_run_on', 'paused_at'])]
class ClientReportSchedule extends Model
{
    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    /** @var array<string, string> */
    public const FREQUENCY_LABELS = [
        self::FREQUENCY_WEEKLY => '每週一寄送上週報表',
        self::FREQUENCY_MONTHLY => '每月 1 日寄送上月報表',
    ];

    public const MAX_RECIPIENTS = 10;

    public const TIMEZONE = 'Asia/Taipei';

    /** @use HasFactory<ClientReportScheduleFactory> */
    use HasFactory;

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ClientReportTemplate::class, 'client_report_template_id');
    }

    public function lastReportShare(): BelongsTo
    {
        return $this->belongsTo(ClientReportShare::class, 'last_report_share_id');
    }

    /** @return list<string> */
    public function recipientList(): array
    {
        return array_values(array_filter(
            explode("\n", (string) $this->recipients),
            static fn (string $recipient): bool => $recipient !== '',
        ));
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    /**
     * The first scheduled date strictly after the given day.
     */
    public static function nextRunAfter(string $frequency, CarbonImmutable $day): CarbonImmutable
    {
        $day = $day->setTimezone(self::TIMEZONE)->startOfDay();

        return $frequency === self::FREQUENCY_MONTHLY
            ? $day->startOfMonth()->addMonthNoOverflow()
            : $day->next(CarbonImmutable::MONDAY);
    }

    /**
     * The completed reporting period that a delivery on the given day covers.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    public static function periodForDeliveryOn(string $frequency, CarbonImmutable $day): array
    {
        $day = $day->setTimezone(self::TIMEZONE)->startOfDay();

        if ($frequency === self::FREQUENCY_MONTHLY) {
            $previousMonth = $day->startOfMonth()->subMonthNoOverflow();

            return ['from' => $previousMonth, 'to' => $previousMonth->endOfMonth()->startOfDay()];
        }

        $latestMonday = $day->isMonday() ? $day : $day->previous(CarbonImmutable::MONDAY);

        return ['from' => $latestMonday->subWeek(), 'to' => $latestMonday->subDay()];
    }

    protected function casts(): array
    {
        return [
            'recipients' => 'encrypted',
            'next_run_on' => 'immutable_date',
            'paused_at' => 'immutable_datetime',
            'last_attempted_at' => 'immutable_datetime',
            'last_delivered_at' => 'immutable_datetime',
            'last_delivered_period_ends_on' => 'immutable_date',
            'consecutive_failures' => 'integer',
        ];
    }
}
