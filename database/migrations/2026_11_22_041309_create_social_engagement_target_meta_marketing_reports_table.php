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
        Schema::create('social_engagement_target_meta_marketing_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_marketing_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_engagement_target_id')->constrained()->cascadeOnDelete();
            $table->string('ad_account_id', 32);
            $table->string('campaign_id', 32);
            $table->string('campaign_name', 255);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('spend_amount', 20, 4);
            $table->unsignedBigInteger('impressions');
            $table->unsignedBigInteger('reach');
            $table->unsignedBigInteger('clicks');
            $table->char('currency_code', 3);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['meta_marketing_connection_id', 'social_engagement_target_id'], 'meta_campaign_report_connection_target_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_engagement_target_meta_marketing_reports');
    }
};
