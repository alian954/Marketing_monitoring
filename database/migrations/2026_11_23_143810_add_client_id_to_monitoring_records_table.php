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
        Schema::table('monitors', function (Blueprint $table): void {
            $table->foreignId('client_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index(['user_id', 'client_id']);
        });

        Schema::table('social_engagement_targets', function (Blueprint $table): void {
            $table->foreignId('client_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->dropUnique('social_engagement_targets_user_id_tag_social_platform_unique');
        });

        foreach (DB::table('users')->select('id')->orderBy('id')->cursor() as $user) {
            $clientId = DB::table('clients')->insertGetId([
                'user_id' => $user->id,
                'name' => '未分類',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('monitors')->where('user_id', $user->id)->whereNull('client_id')->update(['client_id' => $clientId]);
            DB::table('social_engagement_targets')->where('user_id', $user->id)->whereNull('client_id')->update(['client_id' => $clientId]);
        }

        Schema::table('social_engagement_targets', function (Blueprint $table): void {
            $table->unique(
                ['user_id', 'client_id', 'tag', 'social_platform'],
                'social_targets_user_client_tag_platform_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('social_engagement_targets')
            ->select(['user_id', 'tag', 'social_platform'])
            ->groupBy(['user_id', 'tag', 'social_platform'])
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new RuntimeException('Client grouping cannot be rolled back while an account has duplicate campaign tags on the same platform.');
        }

        Schema::table('social_engagement_targets', function (Blueprint $table): void {
            $table->dropUnique('social_targets_user_client_tag_platform_unique');
            $table->dropConstrainedForeignId('client_id');
            $table->unique(['user_id', 'tag', 'social_platform']);
        });

        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'client_id']);
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
