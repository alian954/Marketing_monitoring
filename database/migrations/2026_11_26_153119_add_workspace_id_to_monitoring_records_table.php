<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['monitors', 'social_engagement_targets', 'status_pages', 'notification_outbox'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->foreignId('workspace_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();

                match ($tableName) {
                    'monitors' => $table->unique(['workspace_id', 'url_hash'], 'monitors_workspace_url_hash_unique'),
                    'social_engagement_targets' => $table->unique(
                        ['workspace_id', 'client_id', 'tag', 'social_platform'],
                        'social_targets_workspace_client_tag_platform_unique',
                    ),
                    'status_pages' => $table->unique(
                        ['workspace_id', 'setting_key'],
                        'status_pages_workspace_setting_key_unique',
                    ),
                    'notification_outbox' => $table->index('workspace_id', 'notification_outbox_workspace_id_index'),
                };
            });

            $workspaceId = DB::table('workspaces')
                ->join('organizations', 'organizations.id', '=', 'workspaces.organization_id')
                ->select('workspaces.id')
                ->whereColumn('organizations.owner_user_id', $tableName.'.user_id');

            DB::table($tableName)
                ->whereNull('workspace_id')
                ->whereNotNull('user_id')
                ->update(['workspace_id' => $workspaceId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['notification_outbox', 'status_pages', 'social_engagement_targets', 'monitors'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                match ($tableName) {
                    'monitors' => $table->dropUnique('monitors_workspace_url_hash_unique'),
                    'social_engagement_targets' => $table->dropUnique('social_targets_workspace_client_tag_platform_unique'),
                    'status_pages' => $table->dropUnique('status_pages_workspace_setting_key_unique'),
                    'notification_outbox' => $table->dropIndex('notification_outbox_workspace_id_index'),
                };

                $table->dropConstrainedForeignId('workspace_id');
            });
        }
    }
};
