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
        Schema::table('marketing_alert_events', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable()->after('message');
            $table->foreignId('acknowledged_by_user_id')->nullable()->after('acknowledged_at')->constrained('users')->nullOnDelete();
            $table->date('muted_until')->nullable()->after('acknowledged_by_user_id');
            $table->timestamp('notification_suppressed_at')->nullable()->after('muted_until');

            $table->index(['marketing_alert_rule_id', 'acknowledged_at'], 'marketing_alert_events_rule_ack_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketing_alert_events', function (Blueprint $table) {
            $table->dropIndex('marketing_alert_events_rule_ack_index');
            $table->dropConstrainedForeignId('acknowledged_by_user_id');
            $table->dropColumn(['acknowledged_at', 'muted_until', 'notification_suppressed_at']);
        });
    }
};
