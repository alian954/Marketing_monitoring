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
        Schema::create('client_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->char('source_currency_code', 3);
            $table->char('reporting_currency_code', 3);
            $table->date('effective_on');
            $table->decimal('rate_to_reporting_currency', 20, 8);
            $table->timestamps();

            $table->unique(
                ['client_id', 'source_currency_code', 'reporting_currency_code', 'effective_on'],
                'client_exchange_rates_pair_date_unique',
            );
            $table->index(
                ['client_id', 'reporting_currency_code', 'source_currency_code', 'effective_on'],
                'client_exchange_rates_lookup_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_exchange_rates');
    }
};
