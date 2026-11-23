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
        Schema::table('social_engagement_target_google_ads_reports', function (Blueprint $table): void {
            $table->longText('search_terms_data')->nullable();
            $table->timestamp('search_terms_expires_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_engagement_target_google_ads_reports', function (Blueprint $table): void {
            $table->dropIndex(['search_terms_expires_at']);
            $table->dropColumn(['search_terms_data', 'search_terms_expires_at']);
        });
    }
};
