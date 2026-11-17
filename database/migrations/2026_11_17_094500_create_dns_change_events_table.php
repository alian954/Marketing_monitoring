<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_change_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('check_run_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->json('previous_ips');
            $table->json('current_ips');
            $table->timestamp('detected_at')->index();
            $table->timestamps();
            $table->index(['monitor_id', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_change_events');
    }
};
