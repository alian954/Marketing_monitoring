<?php

use App\Models\Monitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('monitors')
            ->select(['id', 'url'])
            ->whereNull('social_platform')
            ->orderBy('id')
            ->chunkById(200, static function ($monitors): void {
                $monitorIds = [];

                foreach ($monitors as $monitor) {
                    if (Monitor::socialPlatformKeyForUrl(Crypt::decryptString($monitor->url)) === 'dcard') {
                        $monitorIds[] = $monitor->id;
                    }
                }

                if ($monitorIds !== []) {
                    DB::table('monitors')->whereIn('id', $monitorIds)->update(['social_platform' => 'dcard']);
                }
            });
    }

    public function down(): void {}
};
