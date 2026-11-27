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
        Schema::create('client_ad_platform_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20);
            $table->date('metric_on');
            $table->string('campaign_name', 191);
            $table->char('currency_code', 3);
            $table->decimal('spend', 18, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('conversions', 18, 4)->default(0);
            $table->decimal('conversion_value', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['client_id', 'platform', 'metric_on', 'campaign_name'], 'client_ad_platform_daily_metrics_unique');
            $table->index(['client_id', 'platform', 'metric_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_ad_platform_daily_metrics');
    }
};
