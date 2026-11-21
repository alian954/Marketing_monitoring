<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonitorPlatformFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_list_and_csv_can_filter_by_platform(): void
    {
        $monitors = [
            'website' => $this->createMonitor('Website campaign', 'https://example.com/campaign'),
            'facebook' => $this->createMonitor('Facebook post', 'https://www.facebook.com/page/posts/12345'),
            'instagram' => $this->createMonitor('Instagram post', 'https://www.instagram.com/p/ABC123/'),
            'threads' => $this->createMonitor('Threads post', 'https://www.threads.net/@creator/post/ABC123'),
        ];
        $user = User::factory()->create();

        foreach ($monitors as $platform => $monitor) {
            $expectedPlatform = $platform === 'website' ? null : $platform;
            $this->assertDatabaseHas('monitors', [
                'id' => $monitor->id,
                'social_platform' => $expectedPlatform,
            ]);

            $response = $this->actingAs($user)
                ->get(route('monitors.index', ['platform' => $platform]))
                ->assertOk()
                ->assertSee('<option value="'.$platform.'" selected>', false)
                ->assertSee(route('monitors.export', ['platform' => $platform]));

            $this->assertSame([$monitor->id], $response->viewData('monitors')->getCollection()->modelKeys());

            $exportResponse = $this->get(route('monitors.export', ['platform' => $platform]))->assertOk();
            $rows = $this->csvRows($exportResponse->streamedContent());

            $this->assertSame([$monitor->name], array_column($rows, 'name'));
        }
    }

    public function test_monitor_platform_is_recalculated_when_the_url_changes(): void
    {
        $monitor = $this->createMonitor('Changing post', 'https://www.instagram.com/p/ABC123/');
        $monitor->forceFill([
            'url' => 'https://www.threads.net/@creator/post/NEW123',
            'url_hash' => Monitor::fingerprintUrl('https://www.threads.net/@creator/post/NEW123'),
        ])->save();

        $this->assertDatabaseHas('monitors', [
            'id' => $monitor->id,
            'social_platform' => 'threads',
        ]);
    }

    public function test_platform_migration_backfills_existing_encrypted_social_urls(): void
    {
        $migration = require base_path('database/migrations/2026_11_19_020730_add_social_platform_to_monitors_table.php');
        $migration->down();

        $url = 'https://www.facebook.com/page/posts/LEGACY123';
        $legacyMonitor = Monitor::factory()->make([
            'name' => 'Legacy Facebook post',
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
        $legacyAttributes = $legacyMonitor->getAttributes();
        $legacyAttributes['created_at'] = now();
        $legacyAttributes['updated_at'] = now();
        $monitorId = DB::table('monitors')->insertGetId($legacyAttributes);

        $migration->up();

        $this->assertDatabaseHas('monitors', [
            'id' => $monitorId,
            'social_platform' => 'facebook',
        ]);
    }

    public function test_threads_share_link_migration_backfills_existing_encrypted_monitor_rows(): void
    {
        $url = 'https://www.threads.com/share/BAGYFgPihu';
        $monitor = $this->createMonitor('Threads share link', $url);
        DB::table('monitors')->where('id', $monitor->id)->update(['social_platform' => null]);
        $migration = require base_path('database/migrations/2026_11_21_103640_backfill_threads_share_link_platforms.php');

        $migration->up();

        $this->assertDatabaseHas('monitors', [
            'id' => $monitor->id,
            'social_platform' => 'threads',
        ]);
    }

    public function test_monitor_list_and_export_reject_unknown_platform_filters(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('monitors.index'))
            ->get(route('monitors.index', ['platform' => 'youtube']))
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHasErrors(['platform' => '請選擇有效的平台篩選條件。']);

        $this->actingAs($user)
            ->from(route('monitors.index'))
            ->get(route('monitors.export', ['platform' => 'youtube']))
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHasErrors(['platform' => '請選擇有效的平台篩選條件。']);
    }

    private function createMonitor(string $name, string $url): Monitor
    {
        return Monitor::factory()->create([
            'name' => $name,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
    }

    /** @return array<int, array<string, string>> */
    private function csvRows(string $content): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        $headers = str_getcsv(array_shift($lines));
        $rows = array_map(static fn (string $line): array => str_getcsv($line), $lines);

        return array_map(static fn (array $row): array => array_combine($headers, $row), $rows);
    }
}
