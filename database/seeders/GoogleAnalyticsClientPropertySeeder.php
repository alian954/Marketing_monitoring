<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class GoogleAnalyticsClientPropertySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command?->info('GA4 資源需由客戶管理員明確設定；不自動建立客戶資源對應。');
    }
}
