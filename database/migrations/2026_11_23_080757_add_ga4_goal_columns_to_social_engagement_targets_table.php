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
        Schema::table('social_engagement_targets', function (Blueprint $table): void {
            $table->string('ga4_goal_metric', 32)->nullable();
            $table->decimal('ga4_goal_value', 20, 4)->nullable();
            $table->char('ga4_goal_currency', 3)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_engagement_targets', function (Blueprint $table): void {
            $table->dropColumn(['ga4_goal_metric', 'ga4_goal_value', 'ga4_goal_currency']);
        });
    }
};
