<?php

use App\Models\Monitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('monitors')
            ->select(['id', 'url'])
            ->whereNull('social_platform')
            ->orderBy('id')
            ->chunkById(200, static function ($monitors): void {
                $monitorIdsByPlatform = [];

                foreach ($monitors as $monitor) {
                    $platform = Monitor::socialPlatformKeyForUrl(Crypt::decryptString($monitor->url));

                    if ($platform !== null) {
                        $monitorIdsByPlatform[$platform][] = $monitor->id;
                    }
                }

                foreach ($monitorIdsByPlatform as $platform => $monitorIds) {
                    DB::table('monitors')->whereIn('id', $monitorIds)->update(['social_platform' => $platform]);
                }
            });
    }

    public function down(): void {}
};
