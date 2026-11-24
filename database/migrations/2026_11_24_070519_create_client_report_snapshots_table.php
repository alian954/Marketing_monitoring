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
        Schema::create('client_report_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_report_share_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('report_sections');
            $table->date('report_starts_on')->nullable();
            $table->date('report_ends_on')->nullable();
            $table->longText('csv_content');
            $table->char('csv_sha256', 64);
            $table->timestamps();

            $table->index(['client_report_share_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_report_snapshots');
    }
};
