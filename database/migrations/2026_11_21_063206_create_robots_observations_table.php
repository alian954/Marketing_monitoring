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
        Schema::create('robots_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->date('observed_on');
            $table->unsignedInteger('config_version');
            $table->string('file_state', 20);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_type', 80)->nullable();
            $table->char('policy_hash', 64)->nullable();
            $table->json('crawler_decisions')->nullable();
            $table->unsignedInteger('rule_count')->nullable();
            $table->unsignedInteger('sitemap_count')->nullable();
            $table->boolean('policy_changed')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['monitor_id', 'observed_on']);
            $table->index(['monitor_id', 'checked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('robots_observations');
    }
};
