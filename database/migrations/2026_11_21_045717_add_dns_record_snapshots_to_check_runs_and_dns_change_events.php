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
            $table->json('monitor_host_dns_records')->nullable();
        });

        Schema::table('dns_change_events', function (Blueprint $table): void {
            $table->json('previous_records')->nullable();
            $table->json('current_records')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dns_change_events', function (Blueprint $table): void {
            $table->dropColumn(['previous_records', 'current_records']);
        });

        Schema::table('check_runs', function (Blueprint $table): void {
            $table->dropColumn('monitor_host_dns_records');
        });
    }
};
