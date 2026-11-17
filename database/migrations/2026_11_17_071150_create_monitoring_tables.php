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
        Schema::create('monitors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->text('url');
            $table->char('url_hash', 64)->unique();
            $table->string('label', 100)->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('config_version')->default(1);
            $table->json('allowed_redirect_hosts')->nullable();
            $table->string('expected_text', 500)->nullable();
            $table->unsignedInteger('interval_seconds')->default(900);
            $table->timestamp('next_check_at')->nullable()->index();
            $table->timestamp('last_checked_at')->nullable()->index();
            $table->timestamps();
            $table->index(['enabled', 'next_check_at']);
        });

        Schema::create('check_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->char('run_key', 64)->unique();
            $table->unsignedBigInteger('config_version');
            $table->text('config_snapshot');
            $table->string('source', 20)->default('scheduled');
            $table->string('status', 20)->default('queued');
            $table->timestamp('scheduled_at')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedSmallInteger('normal_status')->nullable();
            $table->unsignedSmallInteger('adsbot_status')->nullable();
            $table->string('error_type', 40)->nullable();
            $table->string('error_detail', 300)->nullable();
            $table->string('adsbot_error_type', 40)->nullable();
            $table->unsignedInteger('elapsed_ms')->nullable();
            $table->text('final_url')->nullable();
            $table->json('redirects')->nullable();
            $table->json('resolved_public_ips')->nullable();
            $table->boolean('expected_text_found')->nullable();
            $table->timestamp('tls_expires_at')->nullable();
            $table->timestamps();
            $table->index(['monitor_id', 'scheduled_at']);
            $table->index(['monitor_id', 'config_version', 'status']);
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('config_version');
            $table->string('rule_key', 40);
            $table->string('severity', 20)->default('critical');
            $table->string('state', 20)->default('suspect');
            $table->string('classification', 30)->nullable();
            $table->string('active_key', 191)->nullable()->unique();
            $table->text('note')->nullable();
            $table->unsignedInteger('failure_count')->default(1);
            $table->unsignedInteger('recovery_count')->default(0);
            $table->timestamp('confirm_after')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['monitor_id', 'state']);
            $table->index(['monitor_id', 'config_version', 'rule_key', 'state']);
        });

        Schema::create('incident_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('check_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 30);
            $table->json('evidence')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['incident_id', 'check_run_id', 'event_type'], 'incident_event_once');
            $table->index(['incident_id', 'created_at']);
        });

        Schema::create('notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->char('event_key', 64)->unique();
            $table->string('recipient', 254);
            $table->string('event_type', 30);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('attempt_started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('subject', 180);
            $table->text('body');
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('tls_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->date('observed_on');
            $table->timestamp('expires_at')->nullable();
            $table->string('error_type', 40)->nullable();
            $table->timestamps();
            $table->unique(['monitor_id', 'observed_on']);
        });

        Schema::create('scheduler_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('job_key', 80)->unique();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_succeeded_at')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduler_heartbeats');
        Schema::dropIfExists('tls_observations');
        Schema::dropIfExists('notification_outbox');
        Schema::dropIfExists('incident_events');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('check_runs');
        Schema::dropIfExists('monitors');
    }
};
