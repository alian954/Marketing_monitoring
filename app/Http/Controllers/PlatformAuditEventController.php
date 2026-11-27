<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\PlatformAuditAction;
use App\Models\PlatformAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlatformAuditEventController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'action' => ['nullable', Rule::enum(PlatformAuditAction::class)],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ], [
            'action.enum' => '請選擇有效的操作類型。',
            'organization_id.exists' => '找不到指定的組織。',
            'date_from.date_format' => '開始日期格式無效。',
            'date_to.date_format' => '結束日期格式無效。',
            'date_to.after_or_equal' => '結束日期不可早於開始日期。',
        ]);

        $events = PlatformAuditEvent::query()
            ->with(['actor:id,name,email', 'workspace.organization:id,name'])
            ->when($filters['action'] ?? null, static fn (Builder $query, string $action): Builder => $query->where('action', $action))
            ->when($filters['organization_id'] ?? null, static fn (Builder $query, int|string $organizationId): Builder => $query
                ->whereHas('workspace', static fn (Builder $workspaceQuery): Builder => $workspaceQuery->where('organization_id', $organizationId)))
            ->when($filters['date_from'] ?? null, static fn (Builder $query, string $dateFrom): Builder => $query
                ->where('created_at', '>=', CarbonImmutable::parse($dateFrom, 'Asia/Taipei')->startOfDay()->utc()))
            ->when($filters['date_to'] ?? null, static fn (Builder $query, string $dateTo): Builder => $query
                ->where('created_at', '<', CarbonImmutable::parse($dateTo, 'Asia/Taipei')->addDay()->startOfDay()->utc()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('platform.audit-events.index', [
            'events' => $events,
            'memberNames' => PlatformAuditEvent::memberNamesFor($events->getCollection()),
            'actions' => PlatformAuditAction::cases(),
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'action' => $filters['action'] ?? '',
                'organization_id' => (string) ($filters['organization_id'] ?? ''),
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
            ],
        ]);
    }
}
