<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class GoogleAdsClientAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command?->info('Google Ads 帳戶需由客戶管理員明確驗證並設定；不自動建立客戶帳戶對應。');
    }
}
