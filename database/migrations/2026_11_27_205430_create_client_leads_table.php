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
        Schema::create('client_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('lead_reference', 120);
            $table->date('created_on');
            $table->string('platform', 20);
            $table->string('campaign_name', 191)->nullable();
            $table->string('stage', 20);
            $table->decimal('deal_value', 18, 2)->nullable();
            $table->date('won_on')->nullable();
            $table->char('currency_code', 3);
            $table->text('google_click_id')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'lead_reference']);
            $table->index(['client_id', 'created_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_leads');
    }
};
