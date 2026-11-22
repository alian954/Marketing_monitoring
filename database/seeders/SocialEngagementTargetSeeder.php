<?php

namespace Database\Seeders;

use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use Illuminate\Database\Seeder;

class SocialEngagementTargetSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $monitor = Monitor::query()
            ->whereNotNull('social_platform')
            ->whereNotNull('label')
            ->where('label', '<>', '')
            ->oldest('id')
            ->first(['id', 'social_platform', 'tags', 'label']);

        $tags = $monitor?->tagNames() ?? [];

        if ($monitor === null || $tags === []) {
            return;
        }

        SocialEngagementTarget::query()->updateOrCreate(
            [
                'tag' => $tags[0],
                'social_platform' => $monitor->social_platform,
            ],
            ['target_interactions' => 1000],
        );
    }
}
