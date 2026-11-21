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
        Schema::table('check_runs', function (Blueprint $table): void {
            $table->json('response_public_engagement_metrics')->nullable()->after('response_description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('check_runs', function (Blueprint $table): void {
            $table->dropColumn('response_public_engagement_metrics');
        });
    }
};
