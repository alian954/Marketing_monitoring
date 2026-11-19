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
        Schema::table('notification_outbox', function (Blueprint $table): void {
            $table->foreignId('monitor_id')->nullable()->after('incident_id')->constrained()->nullOnDelete();
            $table->index(['monitor_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_outbox', function (Blueprint $table): void {
            $table->dropIndex(['monitor_id', 'created_at']);
            $table->dropConstrainedForeignId('monitor_id');
        });
    }
};
