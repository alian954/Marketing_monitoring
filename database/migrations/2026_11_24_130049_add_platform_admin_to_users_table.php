<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
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
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_admin')->default(false);
        });

        $workspaceOwners = DB::table('users')
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('workspace_memberships')
                    ->whereColumn('workspace_memberships.member_user_id', 'users.id')
                    ->whereNull('workspace_memberships.revoked_at');
            })
            ->orderBy('id')
            ->get(['id', 'name']);

        foreach ($workspaceOwners as $workspaceOwner) {
            DB::table('organizations')->insert([
                'owner_user_id' => $workspaceOwner->id,
                'name' => mb_substr(trim($workspaceOwner->name) !== '' ? $workspaceOwner->name : '未命名組織', 0, 120),
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($workspaceOwners->count() === 1) {
            DB::table('users')
                ->where('id', $workspaceOwners->first()->id)
                ->update(['is_platform_admin' => true]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_platform_admin');
        });
    }
};
