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
            $table->string('report_brand_name', 80)->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_report_shares', function (Blueprint $table) {
            $table->dropColumn('report_brand_name');
        });
    }
};
