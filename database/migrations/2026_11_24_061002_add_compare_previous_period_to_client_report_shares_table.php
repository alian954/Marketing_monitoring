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
            $table->boolean('compare_previous_period')->default(false)->after('report_ends_on');
        });
        Schema::table('client_report_shares', function (Blueprint $table) {
            //
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_report_shares', function (Blueprint $table): void {
            $table->dropColumn('compare_previous_period');
        });
        Schema::table('client_report_shares', function (Blueprint $table) {
            //
        });
    }
};
