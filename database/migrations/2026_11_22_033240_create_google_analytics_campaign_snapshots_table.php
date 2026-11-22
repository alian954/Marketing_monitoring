<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('google_analytics_campaign_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('google_analytics_connection_id');
            $table->foreign('google_analytics_connection_id', 'ga4_campaign_snapshots_connection_fk')
                ->references('id')
                ->on('google_analytics_connections')
                ->cascadeOnDelete();
            $table->foreignId('social_engagement_target_id');
            $table->foreign('social_engagement_target_id', 'ga4_campaign_snapshots_target_fk')
                ->references('id')
                ->on('social_engagement_targets')
                ->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->string('campaign_name', 100);
            $table->string('source_name', 100)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedBigInteger('sessions');
            $table->unsignedBigInteger('engaged_sessions');
            $table->decimal('key_events', 20, 4);
            $table->decimal('total_revenue', 20, 4);
            $table->char('currency_code', 3)->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(
                ['google_analytics_connection_id', 'social_engagement_target_id', 'snapshot_date'],
                'ga4_target_daily_snapshot_unique',
            );
        });

        DB::table('social_engagement_target_google_analytics_reports')
            ->orderBy('id')
            ->chunkById(200, function ($reports): void {
                foreach ($reports as $report) {
                    $syncedAt = Carbon::parse($report->synced_at, config('app.timezone'));

                    DB::table('google_analytics_campaign_snapshots')->updateOrInsert(
                        [
                            'google_analytics_connection_id' => $report->google_analytics_connection_id,
                            'social_engagement_target_id' => $report->social_engagement_target_id,
                            'snapshot_date' => $syncedAt->copy()->timezone('Asia/Taipei')->toDateString(),
                        ],
                        [
                            'campaign_name' => $report->campaign_name,
                            'source_name' => $report->source_name,
                            'starts_on' => $report->starts_on,
                            'ends_on' => $report->ends_on,
                            'sessions' => $report->sessions,
                            'engaged_sessions' => $report->engaged_sessions,
                            'key_events' => $report->key_events,
                            'total_revenue' => $report->total_revenue,
                            'currency_code' => $report->currency_code,
                            'synced_at' => $report->synced_at,
                            'created_at' => $report->created_at,
                            'updated_at' => $report->updated_at,
                        ],
                    );
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_analytics_campaign_snapshots');
    }
};
