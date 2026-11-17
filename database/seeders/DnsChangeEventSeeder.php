<?php

namespace Database\Seeders;

use App\Models\DnsChangeEvent;
use App\Models\Monitor;
use Illuminate\Database\Seeder;

class DnsChangeEventSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $url = 'https://dns-change-demo.invalid/';
        $monitor = Monitor::query()->firstOrCreate(
            ['url_hash' => Monitor::fingerprintUrl($url)],
            [
                'name' => '示範：DNS 變更',
                'url' => $url,
                'label' => '示範資料（停用）',
                'enabled' => false,
                'config_version' => 1,
                'allowed_redirect_hosts' => [],
                'interval_seconds' => (int) config('monitoring.interval_seconds'),
            ],
        );

        $alreadySeeded = DnsChangeEvent::query()
            ->where('monitor_id', $monitor->id)
            ->whereDate('detected_at', today())
            ->exists();

        if (! $alreadySeeded) {
            DnsChangeEvent::factory()->create([
                'monitor_id' => $monitor->id,
                'check_run_id' => null,
                'detected_at' => now(),
            ]);
        }
    }
}
