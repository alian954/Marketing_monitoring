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
        Schema::create('client_monthly_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7);
            $table->char('currency_code', 3);
            $table->decimal('total_budget', 18, 2);
            $table->decimal('google_ads_budget', 18, 2)->nullable();
            $table->decimal('meta_marketing_budget', 18, 2)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_monthly_budgets');
    }
};
