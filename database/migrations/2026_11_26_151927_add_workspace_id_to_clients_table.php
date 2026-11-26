<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['workspace_id', 'name']);
        });

        $workspaceId = DB::table('workspaces')
            ->join('organizations', 'organizations.id', '=', 'workspaces.organization_id')
            ->select('workspaces.id')
            ->whereColumn('organizations.owner_user_id', 'clients.user_id');

        DB::table('clients')
            ->whereNull('workspace_id')
            ->whereNotNull('user_id')
            ->update(['workspace_id' => $workspaceId]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropUnique(['workspace_id', 'name']);
            $table->dropConstrainedForeignId('workspace_id');
        });
    }
};
