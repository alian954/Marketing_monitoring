<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $ownerId = DB::table('users')->orderBy('id')->value('id');
        $recordCount = DB::table('monitors')->count()
            + DB::table('social_engagement_targets')->count()
            + DB::table('status_pages')->count()
            + DB::table('notification_outbox')->count();

        if ($ownerId === null && $recordCount > 0) {
            throw new RuntimeException('Create an account before migrating existing monitoring records.');
        }

        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropUnique('monitors_url_hash_unique');
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('social_engagement_targets', function (Blueprint $table): void {
            $table->dropUnique('social_engagement_targets_tag_social_platform_unique');
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('status_pages', function (Blueprint $table): void {
            $table->dropUnique('status_pages_setting_key_unique');
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('notification_outbox', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->index()->after('id')->constrained()->cascadeOnDelete();
        });

        if ($ownerId !== null) {
            DB::table('monitors')->whereNull('user_id')->update(['user_id' => $ownerId]);
            DB::table('social_engagement_targets')->whereNull('user_id')->update(['user_id' => $ownerId]);
            DB::table('status_pages')->whereNull('user_id')->update(['user_id' => $ownerId]);
            DB::table('notification_outbox')->whereNull('user_id')->update(['user_id' => $ownerId]);
        }

        Schema::table('monitors', function (Blueprint $table): void {
            $table->unique(['user_id', 'url_hash']);
        });

        Schema::table('social_engagement_targets', function (Blueprint $table): void {
            $table->unique(['user_id', 'tag', 'social_platform']);
        });

        Schema::table('status_pages', function (Blueprint $table): void {
            $table->unique(['user_id', 'setting_key']);
        });
    }

    public function down(): void
    {
        if ($this->hasDuplicateValues('monitors', ['url_hash'])
            || $this->hasDuplicateValues('social_engagement_targets', ['tag', 'social_platform'])
            || $this->hasDuplicateValues('status_pages', ['setting_key'])) {
            throw new RuntimeException('Account ownership cannot be rolled back while separate accounts share monitor URLs, campaign tags, or status page keys.');
        }

        Schema::table('status_pages', function (Blueprint $table): void {
            $table->dropUnique('status_pages_user_id_setting_key_unique');
            $table->dropConstrainedForeignId('user_id');
            $table->unique('setting_key');
        });

        Schema::table('notification_outbox', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('social_engagement_targets', function (Blueprint $table): void {
            $table->dropUnique('social_engagement_targets_user_id_tag_social_platform_unique');
            $table->dropConstrainedForeignId('user_id');
            $table->unique(['tag', 'social_platform']);
        });

        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropUnique('monitors_user_id_url_hash_unique');
            $table->dropConstrainedForeignId('user_id');
            $table->unique('url_hash');
        });
    }

    /** @param list<string> $columns */
    private function hasDuplicateValues(string $tableName, array $columns): bool
    {
        return DB::table($tableName)
            ->select($columns)
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }
};
