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
        Schema::create('integration_application_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('provider_code', 64);
            $table->string('client_id', 255);
            $table->text('client_secret');
            $table->foreignId('last_updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['workspace_id', 'provider_code']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_application_credentials');
    }
};
