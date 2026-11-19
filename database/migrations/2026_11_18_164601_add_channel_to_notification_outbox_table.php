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
            $table->string('channel', 20)->default('email')->after('event_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_outbox', function (Blueprint $table): void {
            $table->dropColumn('channel');
        });
    }
};
