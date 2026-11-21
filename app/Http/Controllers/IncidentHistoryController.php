<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\Monitor;
use App\Services\CsvFormulaSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentHistoryController extends Controller
{
    /** @var array<string, string> */
    private const STATE_LABELS = [
        Incident::STATE_SUSPECT => '確認中',
        Incident::STATE_OPEN => '已確認',
        Incident::STATE_RESOLVED => '已恢復',
        Incident::STATE_PAUSED => '已暫停',
        Incident::STATE_SUPERSEDED => '設定已變更',
    ];

    /** @var array<string, string> */
    private const SEVERITY_LABELS = [
        'critical' => '嚴重',
        'warning' => '警告',
    ];

    public function index(Request $request): View
    {
        $filters = $this->validatedFilters($request);
        $incidents = $this->incidentQuery($filters)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();
        $asOf = now();
        $incidents->getCollection()->each(static function (Incident $incident) use ($asOf): void {
            $incident->setAttribute('confirmed_duration_seconds', $incident->confirmedDurationSeconds($asOf));
            $incident->setAttribute('confirmed_duration_label', $incident->confirmedDurationLabel($asOf));
        });
        $monitors = Monitor::query()->orderBy('name')->get(['id', 'name', 'label']);
        $labels = $monitors->pluck('label')->filter()->unique()->sort()->values();

        return view('incidents.index', [
            'incidents' => $incidents,
            'monitors' => $monitors,
            'labels' => $labels,
            'ruleLabels' => Incident::RULE_LABELS,
            'stateLabels' => self::STATE_LABELS,
            'severityLabels' => self::SEVERITY_LABELS,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'state' => $filters['state'] ?? '',
                'severity' => $filters['severity'] ?? '',
                'acknowledgement' => $filters['acknowledgement'] ?? '',
                'notification_status' => $filters['notification_status'] ?? '',
                'monitor_id' => (string) ($filters['monitor_id'] ?? ''),
                'platform' => $filters['platform'] ?? '',
                'label' => $filters['label'] ?? '',
                'rule_key' => $filters['rule_key'] ?? '',
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->validatedFilters($request);
        $exportedAt = now();

        return response()->streamDownload(function () use ($filters, $exportedAt): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'incident_id',
                'monitor_name',
                'rule_key',
                'severity',
                'state',
                'first_seen_at_utc',
                'classification',
                'failure_count',
                'recovery_count',
                'opened_at_utc',
                'resolved_at_utc',
                'confirmed_duration_seconds',
                'acknowledged_by',
                'acknowledged_at_utc',
                'notifications_snoozed_until_utc',
                'last_seen_at_utc',
                'note',
            ]);

            $this->incidentQuery($filters)
                ->orderBy('id')
                ->lazyById()
                ->each(function (Incident $incident) use ($stream, $exportedAt): void {
                    $confirmedDurationSeconds = $incident->confirmedDurationSeconds($exportedAt);
                    $fields = [
                        (string) $incident->id,
                        $incident->monitor?->name ?? '',
                        $incident->rule_key,
                        $incident->severity,
                        $incident->state,
                        $incident->created_at?->utc()->toIso8601String() ?? '',
                        (string) $incident->classification,
                        (string) $incident->failure_count,
                        (string) $incident->recovery_count,
                        $incident->opened_at?->utc()->toIso8601String() ?? '',
                        $incident->resolved_at?->utc()->toIso8601String() ?? '',
                        $confirmedDurationSeconds === null ? '' : (string) $confirmedDurationSeconds,
                        $incident->acknowledgedBy?->name ?? '',
                        $incident->acknowledged_at?->utc()->toIso8601String() ?? '',
                        $incident->snoozed_until?->utc()->toIso8601String() ?? '',
                        $incident->last_seen_at?->utc()->toIso8601String() ?? '',
                        (string) $incident->note,
                    ];

                    fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $fields), ',', '"', '');
                });

            fclose($stream);
        }, 'incidents.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{search?: string, state?: string, severity?: string, acknowledgement?: string, notification_status?: string, monitor_id?: int|string, platform?: string, label?: string, rule_key?: string, date_from?: string, date_to?: string} */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'in:'.implode(',', array_keys(self::STATE_LABELS))],
            'severity' => ['nullable', 'in:'.implode(',', array_keys(self::SEVERITY_LABELS))],
            'acknowledgement' => ['nullable', 'in:acknowledged,unacknowledged'],
            'notification_status' => ['nullable', 'in:active,snoozed'],
            'monitor_id' => ['nullable', 'integer', 'exists:monitors,id'],
            'platform' => ['nullable', 'in:website,facebook,instagram,threads'],
            'label' => ['nullable', 'string', 'max:100'],
            'rule_key' => ['nullable', 'in:'.implode(',', array_keys(Incident::RULE_LABELS))],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ], [
            'date_from.date_format' => '開始日期格式無效。',
            'date_to.date_format' => '結束日期格式無效。',
            'date_to.after_or_equal' => '結束日期不可早於開始日期。',
            'severity.in' => '請選擇有效的事件嚴重度篩選條件。',
            'acknowledgement.in' => '請選擇有效的事件認領篩選條件。',
            'notification_status.in' => '請選擇有效的提醒狀態篩選條件。',
            'platform.in' => '請選擇有效的平台篩選條件。',
            'label.max' => '標籤篩選不得超過 100 個字元。',
        ]);
    }

    /** @param array{search?: string, state?: string, severity?: string, acknowledgement?: string, notification_status?: string, monitor_id?: int|string, platform?: string, label?: string, rule_key?: string, date_from?: string, date_to?: string} $filters */
    private function incidentQuery(array $filters): Builder
    {
        return Incident::query()
            ->with(['monitor:id,name', 'acknowledgedBy:id,name'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->whereHas('monitor', static function (Builder $monitorQuery) use ($search): void {
                        $monitorQuery->where('name', 'like', '%'.$search.'%');
                    });

                    if (ctype_digit($search)) {
                        $query->orWhere('id', (int) $search);
                    }
                });
            })
            ->when($filters['state'] ?? null, static fn (Builder $query, string $state): Builder => $query->where('state', $state))
            ->when($filters['severity'] ?? null, static fn (Builder $query, string $severity): Builder => $query->where('severity', $severity))
            ->when(($filters['acknowledgement'] ?? '') === 'acknowledged', static fn (Builder $query): Builder => $query->whereNotNull('acknowledged_at'))
            ->when(($filters['acknowledgement'] ?? '') === 'unacknowledged', static fn (Builder $query): Builder => $query->whereNull('acknowledged_at'))
            ->when(($filters['notification_status'] ?? '') === 'snoozed', static fn (Builder $query): Builder => $query->where('snoozed_until', '>', now()))
            ->when(($filters['notification_status'] ?? '') === 'active', static fn (Builder $query): Builder => $query->where(static function (Builder $statusQuery): void {
                $statusQuery->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
            }))
            ->when($filters['monitor_id'] ?? null, static fn (Builder $query, int|string $monitorId): Builder => $query->where('monitor_id', $monitorId))
            ->when($filters['platform'] ?? null, static function (Builder $query, string $platform): void {
                $query->whereHas('monitor', static function (Builder $monitorQuery) use ($platform): void {
                    if ($platform === 'website') {
                        $monitorQuery->whereNull('social_platform');

                        return;
                    }

                    $monitorQuery->where('social_platform', $platform);
                });
            })
            ->when($filters['label'] ?? null, static function (Builder $query, string $label): void {
                $query->whereHas('monitor', static fn (Builder $monitorQuery): Builder => $monitorQuery->where('label', $label));
            })
            ->when($filters['rule_key'] ?? null, static fn (Builder $query, string $ruleKey): Builder => $query->where('rule_key', $ruleKey))
            ->when($filters['date_from'] ?? null, static function (Builder $query, string $dateFrom): Builder {
                $start = CarbonImmutable::parse($dateFrom, 'Asia/Taipei')->startOfDay()->utc();

                return $query->where('created_at', '>=', $start);
            })
            ->when($filters['date_to'] ?? null, static function (Builder $query, string $dateTo): Builder {
                $endExclusive = CarbonImmutable::parse($dateTo, 'Asia/Taipei')->addDay()->startOfDay()->utc();

                return $query->where('created_at', '<', $endExclusive);
            });
    }
}
