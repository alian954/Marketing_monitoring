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
        Schema::create('domain_registration_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->date('observed_on');
            $table->string('domain_name', 253)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('error_type', 80)->nullable();
            $table->timestamps();

            $table->unique(['monitor_id', 'observed_on']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domain_registration_observations');
    }
};
