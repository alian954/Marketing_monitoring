<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['google_analytics_connections', 'google_ads_connections', 'meta_marketing_connections'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('scheduled_sync_status', 20)->nullable();
                $table->timestamp('scheduled_sync_started_at')->nullable();
                $table->timestamp('scheduled_sync_finished_at')->nullable();
                $table->unsignedInteger('scheduled_sync_synced_count')->default(0);
                $table->unsignedInteger('scheduled_sync_skipped_count')->default(0);
                $table->unsignedInteger('scheduled_sync_failed_count')->default(0);
                $table->text('scheduled_sync_error')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['google_analytics_connections', 'google_ads_connections', 'meta_marketing_connections'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn([
                    'scheduled_sync_status',
                    'scheduled_sync_started_at',
                    'scheduled_sync_finished_at',
                    'scheduled_sync_synced_count',
                    'scheduled_sync_skipped_count',
                    'scheduled_sync_failed_count',
                    'scheduled_sync_error',
                ]);
            });
        }
    }
};
