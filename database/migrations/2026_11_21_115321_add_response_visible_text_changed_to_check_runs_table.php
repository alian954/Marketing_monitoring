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
        Schema::table('check_runs', function (Blueprint $table) {
            $table->boolean('response_visible_text_changed')->nullable()->after('response_visible_text_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('check_runs', function (Blueprint $table) {
            $table->dropColumn('response_visible_text_changed');
        });
    }
};
