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
            $table->string('paid_media_goal_source', 32)->nullable();
            $table->decimal('paid_media_goal_roas', 20, 4)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_engagement_targets', function (Blueprint $table): void {
            $table->dropColumn(['paid_media_goal_source', 'paid_media_goal_roas']);
        });
    }
};
