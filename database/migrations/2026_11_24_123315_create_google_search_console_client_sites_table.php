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
        Schema::create('google_search_console_client_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('google_search_console_connection_id')
                ->constrained('google_search_console_connections')
                ->cascadeOnDelete();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('site_url', 2048);
            $table->timestamps();
        });

        DB::table('google_search_console_connections')
            ->whereNotNull('site_url')
            ->where('site_url', '<>', '')
            ->orderBy('id')
            ->get(['id', 'user_id', 'site_url'])
            ->each(function (object $connection): void {
                $clientIds = DB::table('clients')
                    ->where('user_id', $connection->user_id)
                    ->limit(2)
                    ->pluck('id');

                if ($clientIds->count() !== 1) {
                    return;
                }

                DB::table('google_search_console_client_sites')->insert([
                    'google_search_console_connection_id' => $connection->id,
                    'client_id' => $clientIds->first(),
                    'site_url' => $connection->site_url,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_search_console_client_sites');
    }
};
