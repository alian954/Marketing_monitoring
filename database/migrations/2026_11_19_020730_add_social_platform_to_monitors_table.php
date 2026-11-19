<?php

use App\Models\Monitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
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
            $table->string('social_platform', 20)->nullable()->index();
        });

        DB::table('monitors')
            ->select(['id', 'url'])
            ->orderBy('id')
            ->chunkById(200, static function ($monitors): void {
                $monitorIdsByPlatform = [];

                foreach ($monitors as $monitor) {
                    $url = Crypt::decryptString($monitor->url);
                    $platform = Monitor::socialPlatformKeyForUrl($url);

                    if ($platform !== null) {
                        $monitorIdsByPlatform[$platform][] = $monitor->id;
                    }
                }

                foreach ($monitorIdsByPlatform as $platform => $monitorIds) {
                    DB::table('monitors')->whereIn('id', $monitorIds)->update(['social_platform' => $platform]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropIndex(['social_platform']);
            $table->dropColumn('social_platform');
        });
    }
};
