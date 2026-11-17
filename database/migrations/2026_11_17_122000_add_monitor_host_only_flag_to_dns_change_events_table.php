<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_change_events', function (Blueprint $table): void {
            $table->boolean('monitor_host_only')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('dns_change_events', function (Blueprint $table): void {
            $table->dropColumn('monitor_host_only');
        });
    }
};
