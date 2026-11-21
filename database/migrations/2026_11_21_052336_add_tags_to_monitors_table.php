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
        Schema::table('monitors', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('label');
        });

        DB::table('monitors')
            ->select(['id', 'label'])
            ->orderBy('id')
            ->lazyById(500)
            ->each(static function (object $monitor): void {
                $label = trim((string) $monitor->label);
                $tags = $label === '' ? [] : [$label];

                DB::table('monitors')->where('id', $monitor->id)->update([
                    'tags' => json_encode($tags, JSON_THROW_ON_ERROR),
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};
