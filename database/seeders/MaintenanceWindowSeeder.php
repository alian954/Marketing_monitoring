<?php

namespace Database\Seeders;

use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use Illuminate\Database\Seeder;

class MaintenanceWindowSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $url = 'https://maintenance-window-demo.invalid/';
        $monitor = Monitor::query()->firstOrCreate(
            ['url_hash' => Monitor::fingerprintUrl($url)],
            [
                'name' => '示範：維護時段',
                'url' => $url,
                'label' => '示範資料（停用）',
                'enabled' => false,
                'config_version' => 1,
                'allowed_redirect_hosts' => [],
                'interval_seconds' => (int) config('monitoring.interval_seconds'),
            ],
        );

        if (! $monitor->maintenanceWindows()->where('reason', '示範維護時段')->exists()) {
            MaintenanceWindow::factory()->for($monitor)->create([
                'starts_at' => now()->addDay()->startOfHour(),
                'ends_at' => now()->addDay()->startOfHour()->addHours(2),
                'reason' => '示範維護時段',
            ]);
        }
    }
}
