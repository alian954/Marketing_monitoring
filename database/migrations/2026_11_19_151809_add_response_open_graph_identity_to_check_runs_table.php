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
            $table->string('response_open_graph_type', 120)->nullable();
            $table->string('response_canonical_reference_hash', 64)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('check_runs', function (Blueprint $table) {
            $table->dropColumn(['response_open_graph_type', 'response_canonical_reference_hash']);
        });
    }
};
