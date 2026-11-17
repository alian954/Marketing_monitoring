<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_runs', function (Blueprint $table): void {
            $table->json('monitor_host_ips')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('check_runs', function (Blueprint $table): void {
            $table->dropColumn('monitor_host_ips');
        });
    }
};
