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
        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->string('client_scope', 16)->default('all');
        });

        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->string('client_scope', 16)->default('all');
            $table->json('client_ids')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->dropColumn(['client_scope', 'client_ids']);
        });

        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->dropColumn('client_scope');
        });
    }
};
