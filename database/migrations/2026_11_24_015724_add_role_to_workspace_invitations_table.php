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
        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->string('role', 32)->default('read_only');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
