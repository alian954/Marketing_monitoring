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
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_engagement_targets', function (Blueprint $table): void {
            $table->dropColumn(['starts_on', 'ends_on']);
        });
    }
};
