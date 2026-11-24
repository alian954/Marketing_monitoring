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
        Schema::table('social_engagement_target_google_analytics_reports', function (Blueprint $table): void {
            $table->string('property_id', 20)->nullable()->after('google_analytics_connection_id');
        });

        Schema::table('google_analytics_campaign_snapshots', function (Blueprint $table): void {
            $table->string('property_id', 20)->nullable()->after('google_analytics_connection_id');
        });

        foreach (DB::table('google_analytics_connections')
            ->whereNotNull('property_id')
            ->where('property_id', '<>', '')
            ->orderBy('id')
            ->get(['id', 'user_id', 'property_id']) as $connection) {
            $clientIds = DB::table('clients')
                ->where('user_id', $connection->user_id)
                ->orderBy('id')
                ->limit(2)
                ->pluck('id');

            if ($clientIds->count() !== 1) {
                continue;
            }

            $clientId = $clientIds->sole();
            $now = now();

            DB::table('google_analytics_client_properties')->insertOrIgnore([
                'google_analytics_connection_id' => $connection->id,
                'client_id' => $clientId,
                'property_id' => $connection->property_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $targetIds = DB::table('social_engagement_targets')
                ->select('id')
                ->where('client_id', $clientId);

            DB::table('social_engagement_target_google_analytics_reports')
                ->where('google_analytics_connection_id', $connection->id)
                ->whereIn('social_engagement_target_id', $targetIds)
                ->update(['property_id' => $connection->property_id]);

            $snapshotTargetIds = DB::table('social_engagement_targets')
                ->select('id')
                ->where('client_id', $clientId);

            DB::table('google_analytics_campaign_snapshots')
                ->where('google_analytics_connection_id', $connection->id)
                ->whereIn('social_engagement_target_id', $snapshotTargetIds)
                ->update(['property_id' => $connection->property_id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('google_analytics_campaign_snapshots', function (Blueprint $table): void {
            $table->dropColumn('property_id');
        });

        Schema::table('social_engagement_target_google_analytics_reports', function (Blueprint $table): void {
            $table->dropColumn('property_id');
        });
    }
};
