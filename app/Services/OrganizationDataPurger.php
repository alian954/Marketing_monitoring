<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\PlatformAuditAction;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Removes a departed organization's workspace data while keeping its contract, billing and audit records.
 *
 * Root rows are deleted here; every child record (check runs, campaign reports, leads, orders, shares...) goes
 * with them through ON DELETE CASCADE foreign keys.
 */
class OrganizationDataPurger
{
    /**
     * Workspace root tables owned through both the canonical workspace and the legacy owner column.
     *
     * @var array<string, string>
     */
    public const WORKSPACE_ROOT_TABLES = [
        'clients' => '客戶（含名單、訂單、報表、警示與廣告帳戶對應）',
        'monitors' => '監測項目（含檢查紀錄與事件）',
        'social_engagement_targets' => '行銷活動（含廣告與 GA4 報表）',
        'status_pages' => '公開狀態頁與訂閱者',
        'google_analytics_connections' => 'GA4 授權',
        'google_ads_connections' => 'Google Ads 授權',
        'google_search_console_connections' => 'Search Console 授權',
        'meta_marketing_connections' => 'Meta Ads 授權',
        'line_messaging_channels' => 'LINE 官方帳號與推播對象',
        'notification_outbox' => '通知紀錄',
    ];

    /**
     * Tables owned only through the workspace.
     *
     * @var array<string, string>
     */
    public const WORKSPACE_ONLY_TABLES = [
        'integration_application_credentials' => 'OAuth App 憑證',
    ];

    /**
     * Tables owned only through the owner user.
     *
     * @var array<string, array{column: string, label: string}>
     */
    public const OWNER_TABLES = [
        'workspace_memberships' => ['column' => 'owner_user_id', 'label' => '成員存取權'],
        'workspace_invitations' => ['column' => 'owner_user_id', 'label' => '成員邀請'],
        'api_tokens' => ['column' => 'workspace_owner_user_id', 'label' => '資料 API 金鑰'],
    ];

    public function __construct(private PlatformAuditLogger $auditLogger) {}

    /**
     * Why the organization's data may not be purged yet, or null when it may.
     */
    public function refusalReason(Organization $organization): ?string
    {
        if ($organization->data_purged_at !== null) {
            return '此組織的資料已於 '.$organization->data_purged_at->timezone('Asia/Taipei')->format('Y/n/j H:i').' 清除。';
        }

        if ($organization->isAccessible()) {
            return '只能清除已停用或訂閱已到期的組織，請先停用此組織。';
        }

        if ((bool) User::query()->whereKey($organization->owner_user_id)->value('is_platform_admin')) {
            return '平台管理員自有的組織不可清除。';
        }

        return null;
    }

    /**
     * Rows that a purge would delete, by label; child records are included with their parents.
     *
     * @return array<string, int>
     */
    public function preview(Organization $organization): array
    {
        $counts = [];

        foreach ($this->rootQueries($organization) as $label => $query) {
            $counts[$label] = $query->count();
        }

        return $counts;
    }

    /**
     * Delete the workspace data and mark the organization as purged and disabled.
     *
     * @return array<string, int> Deleted root rows by label.
     */
    public function purge(Organization $organization, User $actor): array
    {
        return DB::transaction(function () use ($organization, $actor): array {
            $lockedOrganization = Organization::query()->with('workspace')->lockForUpdate()->findOrFail($organization->getKey());
            $refusalReason = $this->refusalReason($lockedOrganization);

            abort_if($refusalReason !== null, 409, $refusalReason ?? '');

            $deletedCounts = [];

            foreach ($this->rootQueries($lockedOrganization) as $label => $query) {
                $deletedCounts[$label] = $query->delete();
            }

            $lockedOrganization->forceFill([
                'is_enabled' => false,
                'data_purged_at' => now(),
            ])->save();

            $this->auditLogger->record(
                PlatformAuditAction::OrganizationDataPurged,
                $lockedOrganization,
                ['deleted_counts' => $deletedCounts],
                (int) $lockedOrganization->owner_user_id,
                $actor,
            );

            return $deletedCounts;
        });
    }

    /**
     * @return array<string, Builder>
     */
    private function rootQueries(Organization $organization): array
    {
        $ownerUserId = (int) $organization->owner_user_id;
        $workspaceId = $organization->workspace?->getKey();
        $queries = [];

        foreach (self::WORKSPACE_ROOT_TABLES as $table => $label) {
            $queries[$label] = DB::table($table)->where(static function (Builder $query) use ($ownerUserId, $workspaceId): void {
                $query->where('user_id', $ownerUserId);

                if ($workspaceId !== null) {
                    $query->orWhere('workspace_id', $workspaceId);
                }
            });
        }

        foreach (self::WORKSPACE_ONLY_TABLES as $table => $label) {
            $queries[$label] = $workspaceId === null
                ? DB::table($table)->whereRaw('1 = 0')
                : DB::table($table)->where('workspace_id', $workspaceId);
        }

        foreach (self::OWNER_TABLES as $table => $definition) {
            $queries[$definition['label']] = DB::table($table)->where($definition['column'], $ownerUserId);
        }

        return $queries;
    }
}
