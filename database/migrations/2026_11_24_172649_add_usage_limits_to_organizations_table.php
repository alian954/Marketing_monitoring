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
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('plan_name', 80)->nullable()->after('name');
            $table->unsignedSmallInteger('client_limit')->nullable()->after('seat_limit');
            $table->unsignedSmallInteger('monitor_limit')->nullable()->after('client_limit');
            $table->unsignedSmallInteger('campaign_limit')->nullable()->after('monitor_limit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['plan_name', 'client_limit', 'monitor_limit', 'campaign_limit']);
        });
    }
};
