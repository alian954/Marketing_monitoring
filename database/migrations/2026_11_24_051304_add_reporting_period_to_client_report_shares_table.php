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
        Schema::table('client_report_shares', function (Blueprint $table) {
            $table->date('report_starts_on')->nullable()->after('report_sections');
            $table->date('report_ends_on')->nullable()->after('report_starts_on');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_report_shares', function (Blueprint $table) {
            $table->dropColumn(['report_starts_on', 'report_ends_on']);
        });
    }
};
