<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow one account to hold memberships in several workspaces while keeping one row per workspace and member.
     */
    public function up(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->index('member_user_id', 'workspace_memberships_member_user_id_index');
        });

        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->dropUnique('workspace_memberships_member_user_id_unique');
            $table->unique(['owner_user_id', 'member_user_id'], 'workspace_memberships_owner_member_unique');
        });
    }

    /**
     * Restore the single-workspace constraint. Fails closed when an account already belongs to several workspaces.
     */
    public function down(): void
    {
        $hasMultipleWorkspaceMembers = DB::table('workspace_memberships')
            ->select('member_user_id')
            ->groupBy('member_user_id')
            ->havingRaw('count(*) > 1')
            ->exists();

        if ($hasMultipleWorkspaceMembers) {
            throw new RuntimeException('部分帳戶已加入多個工作區，請先人工處理成員資料後再回復單一工作區限制。');
        }

        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->dropUnique('workspace_memberships_owner_member_unique');
            $table->unique('member_user_id', 'workspace_memberships_member_user_id_unique');
        });

        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->dropIndex('workspace_memberships_member_user_id_index');
        });
    }
};
