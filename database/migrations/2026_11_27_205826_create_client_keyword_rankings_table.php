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
        Schema::create('client_keyword_rankings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_tracked_keyword_id')->constrained()->cascadeOnDelete();
            $table->date('measured_on');
            $table->decimal('position', 6, 2)->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr_percent', 6, 2)->nullable();
            $table->timestamps();

            $table->unique(['client_tracked_keyword_id', 'measured_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_keyword_rankings');
    }
};
