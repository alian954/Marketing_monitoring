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
        Schema::create('client_report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_report_template_id')->constrained()->cascadeOnDelete();
            $table->string('frequency', 16);
            $table->text('recipients');
            $table->date('next_run_on');
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('last_delivered_at')->nullable();
            $table->date('last_delivered_period_ends_on')->nullable();
            $table->foreignId('last_report_share_id')->nullable()->constrained('client_report_shares')->nullOnDelete();
            $table->string('last_failure_reason', 255)->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamps();

            $table->index(['paused_at', 'next_run_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_report_schedules');
    }
};
