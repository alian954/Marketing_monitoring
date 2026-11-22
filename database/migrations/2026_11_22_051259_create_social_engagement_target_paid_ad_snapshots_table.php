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
        Schema::create('social_engagement_target_paid_ad_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_engagement_target_id')
                ->constrained('social_engagement_targets', 'id', 'paid_ad_snapshots_target_fk')
                ->cascadeOnDelete();
            $table->foreignId('meta_marketing_connection_id')
                ->nullable()
                ->constrained('meta_marketing_connections', 'id', 'paid_ad_snapshots_meta_connection_fk')
                ->cascadeOnDelete();
            $table->foreignId('google_ads_connection_id')
                ->nullable()
                ->constrained('google_ads_connections', 'id', 'paid_ad_snapshots_google_connection_fk')
                ->cascadeOnDelete();
            $table->string('source', 20);
            $table->string('account_id', 32);
            $table->string('campaign_id', 32);
            $table->string('campaign_name', 255);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('spend_amount', 20, 4);
            $table->unsignedBigInteger('impressions');
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('clicks');
            $table->decimal('conversions', 20, 4)->nullable();
            $table->decimal('conversions_value', 20, 4)->nullable();
            $table->char('currency_code', 3);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(
                ['meta_marketing_connection_id', 'social_engagement_target_id', 'starts_on', 'ends_on', 'account_id', 'synced_at'],
                'paid_ad_snapshot_meta_unique',
            );
            $table->unique(
                ['google_ads_connection_id', 'social_engagement_target_id', 'starts_on', 'ends_on', 'account_id', 'synced_at'],
                'paid_ad_snapshot_google_unique',
            );
            $table->index(['social_engagement_target_id', 'source', 'synced_at'], 'paid_ad_snapshots_source_time_idx');
            $table->index('synced_at', 'paid_ad_snapshots_synced_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_engagement_target_paid_ad_snapshots');
    }
};
