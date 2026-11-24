<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('meta_marketing_client_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_marketing_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('ad_account_id', 32);
            $table->string('ad_account_name', 100);
            $table->char('currency_code', 3);
            $table->timestamps();

            $table->unique(
                ['meta_marketing_connection_id', 'client_id'],
                'meta_marketing_client_account_connection_client_unique',
            );
        });

        DB::table('meta_marketing_connections')
            ->whereNotNull('ad_account_id')
            ->whereNotNull('ad_account_name')
            ->whereNotNull('currency_code')
            ->orderBy('id')
            ->chunkById(100, static function ($connections): void {
                foreach ($connections as $connection) {
                    $clientIds = DB::table('clients')
                        ->where('user_id', $connection->user_id)
                        ->orderBy('id')
                        ->limit(2)
                        ->pluck('id');

                    if ($clientIds->count() !== 1) {
                        continue;
                    }

                    $timestamp = now();
                    DB::table('meta_marketing_client_accounts')->insert([
                        'meta_marketing_connection_id' => $connection->id,
                        'client_id' => $clientIds->sole(),
                        'ad_account_id' => $connection->ad_account_id,
                        'ad_account_name' => $connection->ad_account_name,
                        'currency_code' => $connection->currency_code,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meta_marketing_client_accounts');
    }
};
