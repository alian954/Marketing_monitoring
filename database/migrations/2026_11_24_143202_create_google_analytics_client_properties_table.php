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
        Schema::create('google_analytics_client_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('google_analytics_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('property_id', 20);
            $table->timestamps();

            $table->unique(
                ['google_analytics_connection_id', 'client_id'],
                'ga4_client_property_connection_client_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_analytics_client_properties');
    }
};
