<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opt organizations into subscription-based product access one at a time.
     *
     * Null keeps the legacy behaviour (no subscription check) so existing tenants are unaffected until migrated.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('entitlements_enforced_at')->nullable()->after('is_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('entitlements_enforced_at');
        });
    }
};
