<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central billing ledger: orders, order lines and manually recorded payments.
     *
     * Financial records restrict deletion of their organization so they are never lost through cascades.
     * Composite foreign keys keep every line and payment inside its order's organization.
     */
    public function up(): void
    {
        Schema::create('billing_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('order_number', 32)->unique();
            $table->string('status', 20)->index();
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('total_minor');
            $table->timestamp('issued_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->unique(['id', 'organization_id']);
        });

        Schema::create('billing_order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('billing_order_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_plan_id');
            $table->string('description', 160);
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('unit_amount_minor');
            $table->unsignedBigInteger('amount_minor');
            $table->date('service_starts_on');
            $table->date('service_ends_on');
            $table->timestamps();

            $table->index('billing_order_id');
            $table->foreign(['billing_order_id', 'organization_id'], 'billing_order_items_order_tenant_foreign')
                ->references(['id', 'organization_id'])
                ->on('billing_orders')
                ->cascadeOnDelete();
            $table->foreign(['product_plan_id', 'product_id'], 'billing_order_items_plan_product_foreign')
                ->references(['id', 'product_id'])
                ->on('product_plans')
                ->restrictOnDelete();
        });

        Schema::create('billing_payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('billing_order_id');
            $table->unsignedBigInteger('organization_id');
            $table->string('method', 32);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->timestamp('received_at');
            $table->string('reference', 120)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'received_at']);
            $table->index('billing_order_id');
            $table->foreign(['billing_order_id', 'organization_id'], 'billing_payments_order_tenant_foreign')
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
        Schema::dropIfExists('billing_payments');
        Schema::dropIfExists('billing_order_items');
        Schema::dropIfExists('billing_orders');
    }
};
