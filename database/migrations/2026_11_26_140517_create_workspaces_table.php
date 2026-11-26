<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->timestamps();
        });

        DB::table('organizations')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function (Collection $organizations): void {
                $timestamp = now();
                $workspaces = $organizations->map(static fn (object $organization): array => [
                    'organization_id' => $organization->id,
                    'public_id' => (string) Str::uuid(),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all();

                DB::table('workspaces')->insert($workspaces);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
