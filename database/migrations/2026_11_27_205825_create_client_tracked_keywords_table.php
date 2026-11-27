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
        Schema::create('client_tracked_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('keyword', 150);
            $table->unsignedTinyInteger('target_position')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'keyword']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_tracked_keywords');
    }
};
