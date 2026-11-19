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
        Schema::table('incidents', function (Blueprint $table) {
            $table->timestamp('snoozed_until')->nullable()->after('acknowledged_by_id');
            $table->index(['state', 'snoozed_until', 'opened_at'], 'incidents_snooze_due_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex('incidents_snooze_due_idx');
            $table->dropColumn('snoozed_until');
        });
    }
};
