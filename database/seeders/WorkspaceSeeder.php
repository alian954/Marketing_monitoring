<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WorkspaceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Organization::query()
            ->whereDoesntHave('workspace')
            ->chunkById(100, function (Collection $organizations): void {
                foreach ($organizations as $organization) {
                    $organization->workspace()->create([
                        'public_id' => (string) Str::uuid(),
                    ]);
                }
            });
    }
}
