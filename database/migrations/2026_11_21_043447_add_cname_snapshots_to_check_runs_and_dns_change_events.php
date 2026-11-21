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
            $table->json('monitor_host_cnames')->nullable();
        });

        Schema::table('dns_change_events', function (Blueprint $table): void {
            $table->json('previous_cnames')->nullable();
            $table->json('current_cnames')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dns_change_events', function (Blueprint $table): void {
            $table->dropColumn(['previous_cnames', 'current_cnames']);
        });

        Schema::table('check_runs', function (Blueprint $table): void {
            $table->dropColumn('monitor_host_cnames');
        });
    }
};
