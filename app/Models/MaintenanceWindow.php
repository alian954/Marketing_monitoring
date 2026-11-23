<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MaintenanceWindowFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['monitor_id', 'starts_at', 'ends_at', 'reason', 'repeat_weekly', 'repeat_until'])]
class MaintenanceWindow extends Model
{
    private const TIMEZONE = 'Asia/Taipei';

    /** @use HasFactory<MaintenanceWindowFactory> */
    use HasFactory;

    use ScopesThroughAuthenticatedMonitor;

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'repeat_weekly' => 'boolean',
            'repeat_until' => 'immutable_date',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /**
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    public function occurrenceIntervals(): array
    {
        $startsAt = $this->starts_at->timezone(self::TIMEZONE);
        $endsAt = $this->ends_at->timezone(self::TIMEZONE);

        if (! $this->repeat_weekly || $this->repeat_until === null) {
            return [['starts_at' => $startsAt, 'ends_at' => $endsAt]];
        }

        $repeatUntil = CarbonImmutable::parse($this->repeat_until->format('Y-m-d'), self::TIMEZONE)->startOfDay();
        $dayDifference = (int) $startsAt->startOfDay()->diffInDays($repeatUntil);
        $lastOccurrenceWeek = intdiv(max(0, $dayDifference), 7);
        $occurrences = [];

        for ($week = 0; $week <= $lastOccurrenceWeek; $week++) {
            $occurrences[] = [
                'starts_at' => $startsAt->addWeeks($week),
                'ends_at' => $endsAt->addWeeks($week),
            ];
        }

        return $occurrences;
    }

    public function isActiveAt(DateTimeInterface $time): bool
    {
        $instant = CarbonImmutable::instance($time)->timezone(self::TIMEZONE);

        $occurrence = $this->nextOccurrence($time);

        return $occurrence !== null
            && $occurrence['starts_at']->lessThanOrEqualTo($instant)
            && $occurrence['ends_at']->greaterThan($instant);
    }

    public function hasUpcomingOrActiveOccurrence(DateTimeInterface $time): bool
    {
        return $this->nextOccurrence($time) !== null;
    }

    /**
     * @return array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}|null
     */
    public function nextOccurrence(DateTimeInterface $time): ?array
    {
        $instant = CarbonImmutable::instance($time)->timezone(self::TIMEZONE);

        foreach ($this->occurrenceIntervals() as $interval) {
            if ($interval['ends_at']->greaterThan($instant)) {
                return $interval;
            }
        }

        return null;
    }

    public function overlaps(MaintenanceWindow $other): bool
    {
        $thisIntervals = $this->occurrenceIntervals();
        $otherIntervals = $other->occurrenceIntervals();
        $thisIndex = 0;
        $otherIndex = 0;

        while (isset($thisIntervals[$thisIndex], $otherIntervals[$otherIndex])) {
            $thisInterval = $thisIntervals[$thisIndex];
            $otherInterval = $otherIntervals[$otherIndex];

            if ($thisInterval['starts_at']->lessThan($otherInterval['ends_at'])
                && $thisInterval['ends_at']->greaterThan($otherInterval['starts_at'])) {
                return true;
            }

            if ($thisInterval['ends_at']->lessThanOrEqualTo($otherInterval['starts_at'])) {
                $thisIndex++;
            } else {
                $otherIndex++;
            }
        }

        return false;
    }
}
