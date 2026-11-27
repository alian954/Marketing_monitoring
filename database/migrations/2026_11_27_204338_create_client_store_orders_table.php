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
        Schema::create('client_store_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('order_reference', 120);
            $table->date('ordered_on');
            $table->char('currency_code', 3);
            $table->decimal('revenue', 18, 2);
            $table->unsignedInteger('orders_count')->default(1);
            $table->unsignedInteger('new_customer_orders')->nullable();
            $table->string('source_label', 60)->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'order_reference']);
            $table->index(['client_id', 'ordered_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_store_orders');
    }
};
