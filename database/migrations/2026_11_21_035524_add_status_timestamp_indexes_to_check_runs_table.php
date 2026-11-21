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
        Schema::table('check_runs', function (Blueprint $table) {
            $table->index(['status', 'scheduled_at'], 'check_runs_status_scheduled_at_index');
            $table->index(['status', 'started_at'], 'check_runs_status_started_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('check_runs', function (Blueprint $table) {
            $table->dropIndex('check_runs_status_scheduled_at_index');
            $table->dropIndex('check_runs_status_started_at_index');
        });
    }
};
