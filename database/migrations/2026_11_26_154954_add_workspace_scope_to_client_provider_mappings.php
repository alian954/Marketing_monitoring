<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
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
        $mappings = [
            'google_analytics_client_properties' => [
                'connection_column' => 'google_analytics_connection_id',
                'connection_table' => 'google_analytics_connections',
                'client_foreign' => 'ga_properties_ws_client_foreign',
                'connection_foreign' => 'ga_properties_ws_connection_foreign',
                'client_index' => 'ga_properties_ws_client_index',
                'connection_index' => 'ga_properties_ws_connection_index',
            ],
            'google_search_console_client_sites' => [
                'connection_column' => 'google_search_console_connection_id',
                'connection_table' => 'google_search_console_connections',
                'client_foreign' => 'gsc_sites_ws_client_foreign',
                'connection_foreign' => 'gsc_sites_ws_connection_foreign',
                'client_index' => 'gsc_sites_ws_client_index',
                'connection_index' => 'gsc_sites_ws_connection_index',
            ],
            'meta_marketing_client_accounts' => [
                'connection_column' => 'meta_marketing_connection_id',
                'connection_table' => 'meta_marketing_connections',
                'client_foreign' => 'meta_accounts_ws_client_foreign',
                'connection_foreign' => 'meta_accounts_ws_connection_foreign',
                'client_index' => 'meta_accounts_ws_client_index',
                'connection_index' => 'meta_accounts_ws_connection_index',
            ],
            'google_ads_client_accounts' => [
                'connection_column' => 'google_ads_connection_id',
                'connection_table' => 'google_ads_connections',
                'client_foreign' => 'google_ads_accounts_ws_client_foreign',
                'connection_foreign' => 'google_ads_accounts_ws_connection_foreign',
                'client_index' => 'google_ads_accounts_ws_client_index',
                'connection_index' => 'google_ads_accounts_ws_connection_index',
            ],
        ];

        foreach ($mappings as $tableName => $mapping) {
            $hasMismatchedWorkspace = DB::table($tableName.' as mappings')
                ->join('clients', 'clients.id', '=', 'mappings.client_id')
                ->join($mapping['connection_table'].' as connections', 'connections.id', '=', 'mappings.'.$mapping['connection_column'])
                ->whereNotNull('clients.workspace_id')
                ->where(function (Builder $query): void {
                    $query->whereNull('connections.workspace_id')
                        ->orWhereColumn('connections.workspace_id', '<>', 'clients.workspace_id');
                })
                ->exists();

            if ($hasMismatchedWorkspace) {
                throw new RuntimeException('Provider client mapping rows contain mismatched workspace ownership.');
            }
        }

        Schema::table('clients', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'id'], 'clients_workspace_id_id_unique');
        });

        $connections = [
            'google_analytics_connections' => 'ga_connections_workspace_id_id_unique',
            'google_search_console_connections' => 'gsc_connections_workspace_id_id_unique',
            'meta_marketing_connections' => 'meta_connections_workspace_id_id_unique',
            'google_ads_connections' => 'google_ads_connections_workspace_id_id_unique',
        ];

        foreach ($connections as $tableName => $indexName) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->unique(['workspace_id', 'id'], $indexName);
            });
        }

        foreach ($mappings as $tableName => $mapping) {
            Schema::table($tableName, function (Blueprint $table) use ($mapping): void {
                $table->foreignId('workspace_id')->nullable()->after('id');
                $table->index(['workspace_id', 'client_id'], $mapping['client_index']);
                $table->index(['workspace_id', $mapping['connection_column']], $mapping['connection_index']);
                $table->foreign(['workspace_id', 'client_id'], $mapping['client_foreign'])
                    ->references(['workspace_id', 'id'])
                    ->on('clients')
                    ->cascadeOnDelete();
                $table->foreign(['workspace_id', $mapping['connection_column']], $mapping['connection_foreign'])
                    ->references(['workspace_id', 'id'])
                    ->on($mapping['connection_table'])
                    ->cascadeOnDelete();
            });

            $workspaceId = DB::table('clients')
                ->select('workspace_id')
                ->whereColumn('clients.id', $tableName.'.client_id');

            DB::table($tableName)
                ->whereNull('workspace_id')
                ->update(['workspace_id' => $workspaceId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $mappings = [
            'google_analytics_client_properties' => [
                'connection_column' => 'google_analytics_connection_id',
                'client_foreign' => 'ga_properties_ws_client_foreign',
                'connection_foreign' => 'ga_properties_ws_connection_foreign',
                'client_index' => 'ga_properties_ws_client_index',
                'connection_index' => 'ga_properties_ws_connection_index',
            ],
            'google_search_console_client_sites' => [
                'connection_column' => 'google_search_console_connection_id',
                'client_foreign' => 'gsc_sites_ws_client_foreign',
                'connection_foreign' => 'gsc_sites_ws_connection_foreign',
                'client_index' => 'gsc_sites_ws_client_index',
                'connection_index' => 'gsc_sites_ws_connection_index',
            ],
            'meta_marketing_client_accounts' => [
                'connection_column' => 'meta_marketing_connection_id',
                'client_foreign' => 'meta_accounts_ws_client_foreign',
                'connection_foreign' => 'meta_accounts_ws_connection_foreign',
                'client_index' => 'meta_accounts_ws_client_index',
                'connection_index' => 'meta_accounts_ws_connection_index',
            ],
            'google_ads_client_accounts' => [
                'connection_column' => 'google_ads_connection_id',
                'client_foreign' => 'google_ads_accounts_ws_client_foreign',
                'connection_foreign' => 'google_ads_accounts_ws_connection_foreign',
                'client_index' => 'google_ads_accounts_ws_client_index',
                'connection_index' => 'google_ads_accounts_ws_connection_index',
            ],
        ];
        $driverName = DB::connection()->getDriverName();

        foreach ($mappings as $tableName => $mapping) {
            Schema::table($tableName, function (Blueprint $table) use ($driverName, $mapping): void {
                $clientForeignKey = $driverName === 'sqlite'
                    ? ['workspace_id', 'client_id']
                    : $mapping['client_foreign'];
                $connectionForeignKey = $driverName === 'sqlite'
                    ? ['workspace_id', $mapping['connection_column']]
                    : $mapping['connection_foreign'];

                $table->dropForeign($clientForeignKey);
                $table->dropForeign($connectionForeignKey);
                $table->dropIndex($mapping['client_index']);
                $table->dropIndex($mapping['connection_index']);
                $table->dropColumn('workspace_id');
            });
        }

        $connections = [
            'google_ads_connections' => 'google_ads_connections_workspace_id_id_unique',
            'meta_marketing_connections' => 'meta_connections_workspace_id_id_unique',
            'google_search_console_connections' => 'gsc_connections_workspace_id_id_unique',
            'google_analytics_connections' => 'ga_connections_workspace_id_id_unique',
        ];

        foreach ($connections as $tableName => $indexName) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->dropUnique($indexName);
            });
        }

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropUnique('clients_workspace_id_id_unique');
        });
    }
};
