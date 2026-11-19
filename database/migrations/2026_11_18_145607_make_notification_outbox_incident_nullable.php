<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notification_outbox', function (Blueprint $table): void {
            $table->foreignId('incident_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('notification_outbox')->whereNull('incident_id')->delete();

        Schema::table('notification_outbox', function (Blueprint $table): void {
            $table->foreignId('incident_id')->nullable(false)->change();
        });
    }
};
