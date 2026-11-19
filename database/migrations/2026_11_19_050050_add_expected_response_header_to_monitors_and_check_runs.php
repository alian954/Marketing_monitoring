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
        Schema::table('monitors', function (Blueprint $table) {
            $table->text('expected_response_header')->nullable();
        });

        Schema::table('check_runs', function (Blueprint $table) {
            $table->boolean('expected_response_header_found')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('check_runs', function (Blueprint $table) {
            $table->dropColumn('expected_response_header_found');
        });

        Schema::table('monitors', function (Blueprint $table) {
            $table->dropColumn('expected_response_header');
        });
    }
};
