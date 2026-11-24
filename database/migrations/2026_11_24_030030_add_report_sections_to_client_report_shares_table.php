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
        Schema::table('client_report_shares', function (Blueprint $table): void {
            $table->json('report_sections')->nullable()->after('campaign_target_ids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_report_shares', function (Blueprint $table): void {
            $table->dropColumn('report_sections');
        });
    }
};
