<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only refunds against paid orders, kept inside the order's organization by a composite foreign key.
     */
    public function up(): void
    {
        Schema::create('billing_refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('billing_order_id');
            $table->unsignedBigInteger('organization_id');
            $table->string('method', 32);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->timestamp('refunded_at');
            $table->string('reason', 255);
            $table->string('reference', 120)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('billing_order_id');
            $table->index(['organization_id', 'refunded_at']);
            $table->foreign(['billing_order_id', 'organization_id'], 'billing_refunds_order_tenant_foreign')
                ->references(['id', 'organization_id'])
                ->on('billing_orders')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_refunds');
    }
};
