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
        Schema::create('google_ads_client_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('google_ads_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('customer_id', 10);
            $table->string('login_customer_id', 10)->nullable();
            $table->string('customer_name', 100);
            $table->char('currency_code', 3);
            $table->timestamps();

        });

        DB::table('google_ads_connections')
            ->whereNotNull('customer_id')
            ->whereNotNull('customer_name')
            ->whereNotNull('currency_code')
            ->orderBy('id')
            ->chunkById(100, static function ($connections): void {
                foreach ($connections as $connection) {
                    if (preg_match('/^\d{10}$/', (string) $connection->customer_id) !== 1
                        || trim((string) $connection->customer_name) === ''
                        || preg_match('/^[A-Z]{3}$/', (string) $connection->currency_code) !== 1
                        || ($connection->login_customer_id !== null
                            && preg_match('/^\d{10}$/', (string) $connection->login_customer_id) !== 1)) {
                        continue;
                    }

                    $clientIds = DB::table('clients')
                        ->where('user_id', $connection->user_id)
                        ->orderBy('id')
                        ->limit(2)
                        ->pluck('id');

                    if ($clientIds->count() !== 1) {
                        continue;
                    }

                    $timestamp = now();
                    DB::table('google_ads_client_accounts')->insert([
                        'google_ads_connection_id' => $connection->id,
                        'client_id' => $clientIds->sole(),
                        'customer_id' => $connection->customer_id,
                        'login_customer_id' => $connection->login_customer_id,
                        'customer_name' => $connection->customer_name,
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
        Schema::dropIfExists('google_ads_client_accounts');
    }
};
