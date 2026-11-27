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
        Schema::table('monitors', function (Blueprint $table) {
            $table->json('expected_tracking_tags')->nullable();
            $table->boolean('alert_on_utm_loss')->default(false);
        });

        Schema::table('check_runs', function (Blueprint $table) {
            $table->json('response_tracking_tags')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('check_runs', function (Blueprint $table) {
            $table->dropColumn('response_tracking_tags');
        });

        Schema::table('monitors', function (Blueprint $table) {
            $table->dropColumn(['expected_tracking_tags', 'alert_on_utm_loss']);
        });
    }
};
