<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->boolean('public_status')->default(false)->index();
        });

        Schema::create('status_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('setting_key')->unique();
            $table->string('title', 120);
            $table->text('public_token');
            $table->string('public_token_hash', 64)->unique();
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_pages');

        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('public_status');
        });
    }
};
