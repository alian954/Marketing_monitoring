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
        Schema::table('marketing_alert_rules', function (Blueprint $table) {
            $table->foreignId('line_notification_target_id')->nullable()->after('webhook_channel')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketing_alert_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('line_notification_target_id');
        });
    }
};
