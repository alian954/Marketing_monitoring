<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remember which end date an expiry reminder was sent for, so a renewal (new end date) is reminded again.
     */
    public function up(): void
    {
        Schema::table('subscription_items', function (Blueprint $table) {
            $table->timestamp('expiry_reminder_sent_for')->nullable()->after('ends_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_items', function (Blueprint $table) {
            $table->dropColumn('expiry_reminder_sent_for');
        });
    }
};
