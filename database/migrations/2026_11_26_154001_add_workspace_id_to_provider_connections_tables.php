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
        $connections = [
            'google_analytics_connections' => 'ga_connections_workspace_id_unique',
            'google_search_console_connections' => 'gsc_connections_workspace_id_unique',
            'meta_marketing_connections' => 'meta_connections_workspace_id_unique',
            'google_ads_connections' => 'google_ads_connections_workspace_id_unique',
        ];

        foreach ($connections as $tableName => $workspaceIndexName) {
            Schema::table($tableName, function (Blueprint $table) use ($workspaceIndexName): void {
                $table->foreignId('workspace_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
                $table->unique('workspace_id', $workspaceIndexName);
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
        $connections = [
            'google_ads_connections' => 'google_ads_connections_workspace_id_unique',
            'meta_marketing_connections' => 'meta_connections_workspace_id_unique',
            'google_search_console_connections' => 'gsc_connections_workspace_id_unique',
            'google_analytics_connections' => 'ga_connections_workspace_id_unique',
        ];

        foreach ($connections as $tableName => $workspaceIndexName) {
            Schema::table($tableName, function (Blueprint $table) use ($workspaceIndexName): void {
                $table->dropUnique($workspaceIndexName);
                $table->dropConstrainedForeignId('workspace_id');
            });
        }
    }
};
