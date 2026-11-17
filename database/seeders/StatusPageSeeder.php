<?php

namespace Database\Seeders;

use App\Models\StatusPage;
use Illuminate\Database\Seeder;

class StatusPageSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        if (! StatusPage::query()->where('setting_key', 'default')->exists()) {
            StatusPage::factory()->create(['setting_key' => 'default']);
        }
    }
}
