<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\PlatformAuditAction;
use App\Models\PlatformAuditEvent;
use App\Models\User;
use App\Services\OrganizationDataPurger;
use App\Services\PlatformAuditLogger;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PlatformOrganizationController extends Controller
{
    public function __construct(
        private PlatformAuditLogger $auditLogger,
        private OrganizationDataPurger $dataPurger,
    ) {}

    public function index(): View
    {
        $organizations = Organization::query()
            ->with('owner:id,name,email')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        $ownerIds = $organizations->pluck('owner_user_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $memberCounts = $this->countByOwner('workspace_memberships', 'owner_user_id', $ownerIds, true);
        $clientCounts = $this->countByOwner('clients', 'user_id', $ownerIds, excludeDefaultClients: true);
        $monitorCounts = $this->countByOwner('monitors', 'user_id', $ownerIds);
        $campaignCounts = $this->countByOwner('social_engagement_targets', 'user_id', $ownerIds);
        $pendingInvitationCounts = $this->countPendingInvitationsByOwner($ownerIds);
        $incidentCounts = $this->countIncidentsByOwner($ownerIds);
        $organizationRows = $organizations->map(static function (Organization $organization) use ($memberCounts, $clientCounts, $monitorCounts, $campaignCounts, $pendingInvitationCounts, $incidentCounts): array {
            $ownerId = (int) $organization->owner_user_id;
            $accountCount = 1 + ($memberCounts[$ownerId] ?? 0);

            return [
                'organization' => $organization,
                'account_count' => $accountCount,
                'pending_invitation_count' => $pendingInvitationCounts[$ownerId] ?? 0,
                'occupied_seat_count' => $accountCount + ($pendingInvitationCounts[$ownerId] ?? 0),
                'client_count' => $clientCounts[$ownerId] ?? 0,
                'monitor_count' => $monitorCounts[$ownerId] ?? 0,
                'campaign_count' => $campaignCounts[$ownerId] ?? 0,
                'incident_count' => $incidentCounts[$ownerId] ?? 0,
            ];
        });

        return view('platform.index', [
            'organizationRows' => $organizationRows,
            'organizationCount' => $organizations->count(),
            'activeOrganizationCount' => $organizations->filter(static fn (Organization $organization): bool => $organization->isAccessible())->count(),
            'accountCount' => User::query()->count(),
            'clientCount' => DB::table('clients')->where('name', '<>', Client::DEFAULT_NAME)->count(),
            'monitorCount' => DB::table('monitors')->count(),
            'campaignCount' => DB::table('social_engagement_targets')->count(),
            'incidentCount' => DB::table('incidents')->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'string', 'email', 'max:254', 'unique:users,email'],
            'owner_password' => ['required', 'string', 'min:12', 'max:200', 'confirmed'],
            'subscription_ends_on' => ['nullable', 'date'],
            'seat_limit' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'plan_name' => ['required', 'string', 'max:80'],
            'client_limit' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'monitor_limit' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'campaign_limit' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ], [
            'name.required' => '請輸入組織名稱。',
            'owner_name.required' => '請輸入組織負責人姓名。',
            'owner_email.required' => '請輸入組織負責人電子郵件。',
            'owner_email.email' => '請輸入有效的電子郵件地址。',
            'owner_email.unique' => '此電子郵件已經有帳號。',
            'owner_password.min' => '初始密碼至少需要 12 個字元。',
            'owner_password.confirmed' => '兩次輸入的初始密碼不一致。',
            'seat_limit.integer' => '席次上限必須是整數。',
            'seat_limit.min' => '席次上限至少為 1；若不設上限請留空。',
            'seat_limit.max' => '席次上限不得超過 65535。',
            'plan_name.required' => '請設定組織方案名稱。',
            'plan_name.max' => '方案名稱不可超過 80 個字元。',
            'client_limit.integer' => '客戶上限必須是整數。',
            'client_limit.min' => '客戶上限至少為 1；若不設上限請留空。',
            'client_limit.max' => '客戶上限不得超過 65535。',
            'monitor_limit.integer' => '監測項目上限必須是整數。',
            'monitor_limit.min' => '監測項目上限至少為 1；若不設上限請留空。',
            'monitor_limit.max' => '監測項目上限不得超過 65535。',
            'campaign_limit.integer' => '活動上限必須是整數。',
            'campaign_limit.min' => '活動上限至少為 1；若不設上限請留空。',
            'campaign_limit.max' => '活動上限不得超過 65535。',
        ]);
        $ownerEmail = mb_strtolower(trim($validated['owner_email']));

        if (User::query()->whereRaw('lower(email) = ?', [$ownerEmail])->exists()) {
            throw ValidationException::withMessages([
                'owner_email' => '此電子郵件已經有帳號。',
            ]);
        }

        DB::transaction(function () use ($validated, $ownerEmail): void {
            $owner = User::query()->create([
                'name' => trim($validated['owner_name']),
                'email' => $ownerEmail,
                'password' => $validated['owner_password'],
            ]);

            $organization = Organization::query()->create([
                'name' => trim($validated['name']),
                'owner_user_id' => $owner->getKey(),
                'subscription_ends_on' => $this->subscriptionEndDate($validated['subscription_ends_on'] ?? null),
                'seat_limit' => $validated['seat_limit'] ?? null,
                'plan_name' => $this->planName($validated['plan_name'] ?? null),
                'client_limit' => $validated['client_limit'] ?? null,
                'monitor_limit' => $validated['monitor_limit'] ?? null,
                'campaign_limit' => $validated['campaign_limit'] ?? null,
                'is_enabled' => true,
            ]);

            $organization->workspace()->create([
                'public_id' => (string) Str::uuid(),
            ]);

            $this->auditLogger->record(
                PlatformAuditAction::OrganizationCreated,
                $organization,
                $this->auditedOrganizationSettings($organization),
                (int) $owner->getKey(),
            );
        });

        return redirect()->route('platform.index')->with('status', '組織與負責人帳號已建立。');
    }

    public function update(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'subscription_ends_on' => ['nullable', 'date'],
            'seat_limit' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'plan_name' => ['nullable', 'string', 'max:80'],
            'client_limit' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'monitor_limit' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'campaign_limit' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'is_enabled' => ['required', 'boolean'],
        ], [
            'name.required' => '請輸入組織名稱。',
            'seat_limit.integer' => '席次上限必須是整數。',
            'seat_limit.min' => '席次上限至少為 1；若不設上限請留空。',
            'seat_limit.max' => '席次上限不得超過 65535。',
            'plan_name.max' => '方案名稱不可超過 80 個字元。',
            'client_limit.integer' => '客戶上限必須是整數。',
            'client_limit.min' => '客戶上限至少為 1；若不設上限請留空。',
            'client_limit.max' => '客戶上限不得超過 65535。',
            'monitor_limit.integer' => '監測項目上限必須是整數。',
            'monitor_limit.min' => '監測項目上限至少為 1；若不設上限請留空。',
            'monitor_limit.max' => '監測項目上限不得超過 65535。',
            'campaign_limit.integer' => '活動上限必須是整數。',
            'campaign_limit.min' => '活動上限至少為 1；若不設上限請留空。',
            'campaign_limit.max' => '活動上限不得超過 65535。',
            'is_enabled.required' => '請選擇組織狀態。',
            'is_enabled.boolean' => '組織狀態格式無效。',
        ]);

        $usageSettings = [];

        foreach (['plan_name', 'client_limit', 'monitor_limit', 'campaign_limit'] as $field) {
            if (array_key_exists($field, $validated)) {
                $usageSettings[$field] = $field === 'plan_name'
                    ? $this->planName($validated[$field])
                    : $validated[$field];
            }
        }

        DB::transaction(function () use ($organization, $validated, $usageSettings): void {
            $lockedOrganization = Organization::query()
                ->lockForUpdate()
                ->findOrFail($organization->getKey());

            $settingsBefore = $this->auditedOrganizationSettings($lockedOrganization);

            $lockedOrganization->update([
                'name' => trim($validated['name']),
                'subscription_ends_on' => $this->subscriptionEndDate($validated['subscription_ends_on'] ?? null),
                'seat_limit' => $validated['seat_limit'] ?? null,
                'is_enabled' => $validated['is_enabled'],
                ...$usageSettings,
            ]);

            $changes = $this->auditLogger->changes(
                $settingsBefore,
                $this->auditedOrganizationSettings($lockedOrganization->refresh()),
            );

            if ($changes !== []) {
                $this->auditLogger->record(
                    PlatformAuditAction::OrganizationUpdated,
                    $lockedOrganization,
                    ['changes' => $changes],
                    (int) $lockedOrganization->owner_user_id,
                );
            }
        });

        return redirect()->route('platform.index')->with('status', '組織設定已更新。');
    }

    /**
     * Opt an organization into (or out of) subscription-based product access.
     */
    public function updateEntitlementEnforcement(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'enforce' => ['required', 'boolean'],
        ], [
            'enforce.required' => '請選擇授權模式。',
            'enforce.boolean' => '授權模式格式無效。',
        ]);
        $enforce = filter_var($validated['enforce'], FILTER_VALIDATE_BOOLEAN);

        DB::transaction(function () use ($organization, $enforce): void {
            $lockedOrganization = Organization::query()->lockForUpdate()->findOrFail($organization->getKey());
            $wasEnforced = $lockedOrganization->enforcesEntitlements();

            if ($wasEnforced === $enforce) {
                return;
            }

            $lockedOrganization->forceFill(['entitlements_enforced_at' => $enforce ? now() : null])->save();
            $this->auditLogger->record(PlatformAuditAction::OrganizationUpdated, $lockedOrganization, [
                'changes' => $this->auditLogger->changes(
                    ['entitlements_enforced' => $wasEnforced],
                    ['entitlements_enforced' => $enforce],
                ),
            ], (int) $lockedOrganization->owner_user_id);
        });

        return redirect()->route('platform.index')->with('status', $enforce
            ? '「'.$organization->name.'」已改為依訂閱授權產品功能。'
            : '「'.$organization->name.'」已恢復舊模式，不依訂閱限制產品功能。');
    }

    /**
     * Read-only support view of one organization; administrators no longer work inside customer workspaces.
     */
    public function show(Organization $organization): View
    {
        $organization->load(['owner:id,name,email', 'workspace']);
        $ownerId = (int) $organization->owner_user_id;
        $members = DB::table('workspace_memberships')
            ->join('users', 'users.id', '=', 'workspace_memberships.member_user_id')
            ->where('workspace_memberships.owner_user_id', $ownerId)
            ->whereNull('workspace_memberships.revoked_at')
            ->orderBy('users.name')
            ->get([
                'users.name',
                'users.email',
                'workspace_memberships.role',
                'workspace_memberships.client_scope',
                'workspace_memberships.accepted_at',
            ]);
        $clients = DB::table('clients')
            ->where('user_id', $ownerId)
            ->orderByRaw('name = ? DESC', [Client::DEFAULT_NAME])
            ->orderBy('name')
            ->get(['id', 'name', 'created_at']);
        $clientIds = $clients->pluck('id')->all();
        $monitorCountsByClient = $this->countByClient('monitors', $clientIds);
        $campaignCountsByClient = $this->countByClient('social_engagement_targets', $clientIds);
        $recentIncidents = DB::table('incidents')
            ->join('monitors', 'monitors.id', '=', 'incidents.monitor_id')
            ->where('monitors.user_id', $ownerId)
            ->orderByDesc('incidents.opened_at')
            ->orderByDesc('incidents.id')
            ->limit(10)
            ->get([
                'incidents.rule_key',
                'incidents.severity',
                'incidents.state',
                'incidents.opened_at',
                'incidents.resolved_at',
                'monitors.name as monitor_name',
            ]);
        $openIncidentCount = DB::table('incidents')
            ->join('monitors', 'monitors.id', '=', 'incidents.monitor_id')
            ->where('monitors.user_id', $ownerId)
            ->whereIn('incidents.state', [Incident::STATE_SUSPECT, Incident::STATE_OPEN])
            ->count();
        $recentAuditEvents = $organization->workspace === null
            ? collect()
            : PlatformAuditEvent::query()
                ->with('actor:id,name,email')
                ->where('workspace_id', $organization->workspace->getKey())
                ->latest('created_at')
                ->latest('id')
                ->limit(10)
                ->get();

        return view('platform.organizations.show', [
            'organization' => $organization,
            'members' => $members,
            'clientRows' => $clients->map(static fn (object $client): array => [
                'name' => $client->name,
                'is_default' => $client->name === Client::DEFAULT_NAME,
                'monitor_count' => $monitorCountsByClient[(int) $client->id] ?? 0,
                'campaign_count' => $campaignCountsByClient[(int) $client->id] ?? 0,
            ]),
            'monitorCount' => array_sum($monitorCountsByClient),
            'campaignCount' => array_sum($campaignCountsByClient),
            'openIncidentCount' => $openIncidentCount,
            'recentIncidents' => $recentIncidents,
            'recentAuditEvents' => $recentAuditEvents,
            'purgeRefusalReason' => $this->dataPurger->refusalReason($organization),
            'purgePreview' => $organization->data_purged_at === null ? $this->dataPurger->preview($organization) : [],
        ]);
    }

    /**
     * Permanently delete a departed organization's workspace data; contract, billing and audit records stay.
     */
    public function purgeData(Request $request, Organization $organization): RedirectResponse
    {
        $request->validate([
            'confirmation_name' => ['required', 'string', 'max:255'],
            'current_password' => ['required', 'string', 'max:200', 'current_password:'.PlatformAuthController::GUARD],
        ], [
            'confirmation_name.required' => '請輸入組織名稱確認。',
            'current_password.required' => '請輸入管理員密碼。',
            'current_password.current_password' => '管理員密碼不正確。',
        ]);

        if (! hash_equals((string) $organization->name, trim((string) $request->input('confirmation_name')))) {
            throw ValidationException::withMessages(['confirmation_name' => '輸入的組織名稱不一致。']);
        }

        $refusalReason = $this->dataPurger->refusalReason($organization);

        if ($refusalReason !== null) {
            throw ValidationException::withMessages(['confirmation_name' => $refusalReason]);
        }

        $actor = $request->user(PlatformAuthController::GUARD);
        abort_unless($actor instanceof User, 401);

        $deletedCounts = $this->dataPurger->purge($organization, $actor);

        return redirect()
            ->route('platform.organizations.show', $organization)
            ->with('status', '已清除「'.$organization->name.'」的工作區資料，共刪除 '.number_format(array_sum($deletedCounts)).' 筆主要資料；帳務與稽核紀錄已保留。');
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, int>
     */
    private function countByClient(string $table, array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        return DB::table($table)
            ->whereIn('client_id', $clientIds)
            ->select('client_id')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('client_id')
            ->pluck('aggregate', 'client_id')
            ->mapWithKeys(static fn (mixed $count, mixed $clientId): array => [(int) $clientId => (int) $count])
            ->all();
    }

    /** @param list<int> $ownerIds
     * @return array<int, int>
     */
    private function countByOwner(
        string $table,
        string $ownerColumn,
        array $ownerIds,
        bool $activeMembershipsOnly = false,
        bool $excludeDefaultClients = false,
    ): array {
        if ($ownerIds === []) {
            return [];
        }

        return DB::table($table)
            ->whereIn($ownerColumn, $ownerIds)
            ->when($activeMembershipsOnly, static fn (Builder $query): Builder => $query->whereNull('revoked_at'))
            ->when($excludeDefaultClients, static fn (Builder $query): Builder => $query->where('name', '<>', Client::DEFAULT_NAME))
            ->select($ownerColumn)
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy($ownerColumn)
            ->pluck('aggregate', $ownerColumn)
            ->mapWithKeys(static fn (mixed $count, mixed $ownerId): array => [(int) $ownerId => (int) $count])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function auditedOrganizationSettings(Organization $organization): array
    {
        return [
            'name' => $organization->name,
            'plan_name' => $organization->plan_name,
            'subscription_ends_on' => $organization->subscription_ends_on?->toDateString(),
            'seat_limit' => $organization->seat_limit,
            'client_limit' => $organization->client_limit,
            'monitor_limit' => $organization->monitor_limit,
            'campaign_limit' => $organization->campaign_limit,
            'is_enabled' => (bool) $organization->is_enabled,
        ];
    }

    private function subscriptionEndDate(?string $date): ?string
    {
        return $date === null || trim($date) === '' ? null : Carbon::parse($date, 'Asia/Taipei')->toDateString();
    }

    private function planName(?string $planName): ?string
    {
        $planName = trim((string) $planName);

        return $planName === '' ? null : $planName;
    }

    /** @param list<int> $ownerIds
     * @return array<int, int>
     */
    private function countIncidentsByOwner(array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }

        return DB::table('incidents')
            ->join('monitors', 'monitors.id', '=', 'incidents.monitor_id')
            ->whereIn('monitors.user_id', $ownerIds)
            ->select('monitors.user_id')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('monitors.user_id')
            ->pluck('aggregate', 'monitors.user_id')
            ->mapWithKeys(static fn (mixed $count, mixed $ownerId): array => [(int) $ownerId => (int) $count])
            ->all();
    }

    /** @param list<int> $ownerIds
     * @return array<int, int>
     */
    private function countPendingInvitationsByOwner(array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }

        return DB::table('workspace_invitations')
            ->whereIn('owner_user_id', $ownerIds)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->select('owner_user_id')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('owner_user_id')
            ->pluck('aggregate', 'owner_user_id')
            ->mapWithKeys(static fn (mixed $count, mixed $ownerId): array => [(int) $ownerId => (int) $count])
            ->all();
    }
}
