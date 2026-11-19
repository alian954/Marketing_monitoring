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
            $table->foreignId('status_page_subscription_id')
                ->nullable()
                ->after('monitor_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_outbox', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('status_page_subscription_id');
        });
    }
};
