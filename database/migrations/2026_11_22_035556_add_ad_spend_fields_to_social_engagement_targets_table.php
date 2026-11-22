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
        Schema::table('social_engagement_targets', function (Blueprint $table) {
            $table->decimal('ad_spend_amount', 20, 4)->nullable();
            $table->char('ad_spend_currency', 3)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_engagement_targets', function (Blueprint $table) {
            $table->dropColumn(['ad_spend_amount', 'ad_spend_currency']);
        });
    }
};
