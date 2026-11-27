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
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('product_code', 80)->unique();
            $table->string('name', 120);
            $table->string('version', 40);
            $table->unsignedSmallInteger('manifest_version');
            $table->string('lifecycle', 20)->index();
            $table->string('entry_route', 120);
            $table->timestamps();
        });

        Schema::create('product_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 120);
            $table->timestamps();
            $table->unique(['product_id', 'feature_key']);
        });

        Schema::create('product_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('plan_code', 80);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('name', 120);
            $table->string('status', 20)->default('draft')->index();
            $table->string('billing_interval', 20)->default('monthly');
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'plan_code', 'version']);
            $table->unique(['id', 'product_id']);
        });

        Schema::create('plan_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_plan_id');
            $table->string('feature_key', 120);
            $table->boolean('is_enabled')->default(false);
            $table->unsignedBigInteger('limit_value')->nullable();
            $table->timestamps();
            $table->unique(['product_plan_id', 'feature_key']);
            $table->foreign(['product_plan_id', 'product_id'], 'plan_entitlements_plan_product_foreign')
                ->references(['id', 'product_id'])
                ->on('product_plans')
                ->cascadeOnDelete();
            $table->foreign(['product_id', 'feature_key'], 'plan_entitlements_feature_product_foreign')
                ->references(['product_id', 'feature_key'])
                ->on('product_features')
                ->cascadeOnDelete();
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->index();
            $table->timestamp('starts_at');
            $table->timestamp('current_period_starts_at');
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->string('billing_provider', 24)->nullable();
            $table->string('provider_subscription_id', 160)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->unique(['billing_provider', 'provider_subscription_id'], 'subscriptions_provider_reference_unique');
            $table->unique(['id', 'organization_id']);
        });

        Schema::create('subscription_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_plan_id');
            $table->string('status', 20)->default('active')->index();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'product_id']);
            $table->index(['subscription_id', 'status']);
            $table->foreign(['subscription_id', 'organization_id'], 'subscription_items_subscription_tenant_foreign')
                ->references(['id', 'organization_id'])
                ->on('subscriptions')
                ->cascadeOnDelete();
            $table->foreign(['product_plan_id', 'product_id'], 'subscription_items_plan_product_foreign')
                ->references(['id', 'product_id'])
                ->on('product_plans')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plan_entitlements');
        Schema::dropIfExists('product_plans');
        Schema::dropIfExists('product_features');
        Schema::dropIfExists('products');
    }
};
