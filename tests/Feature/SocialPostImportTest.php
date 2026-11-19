<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Models\Monitor;
use App\Models\User;
use App\Services\Probe\HostResolver;
use App\Services\Probe\UrlSafetyPolicy;
use App\Services\RunScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SocialPostImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new class implements HostResolver
        {
            /** @return list<string> */
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };

        $this->app->instance(UrlSafetyPolicy::class, new UrlSafetyPolicy($resolver));
    }

    public function test_csv_can_preview_and_import_social_post_text_conditions(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $user = User::factory()->create();
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_text'],
            [[
                'Spring campaign',
                'https://www.instagram.com/p/ABC123/',
                'Campaign',
                'Campaign, launch headline',
            ]],
        );

        $preview = $this->actingAs($user)
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('預期文字條件')
            ->assertSee('Campaign, launch headline')
            ->assertSee('可匯入');

        $this->assertSame(1, $preview->viewData('validCount'));
        $previewRow = $preview->viewData('previewRows')[0];
        $this->assertTrue($previewRow['eligible']);
        $this->assertSame('present', $previewRow['expected_text_mode']);
        $this->assertDatabaseCount('monitors', 0);

        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $monitor = Monitor::query()->sole();
        $this->assertSame('Campaign, launch headline', $monitor->expected_text);
        $this->assertSame('present', $monitor->expected_text_mode);
        $this->assertSame('Instagram', $monitor->socialPlatform());

        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);
        $snapshot = json_decode($run->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Campaign, launch headline', $snapshot['expected_text']);
        $this->assertSame('present', $snapshot['expected_text_mode']);
        Queue::assertPushed(RunMonitorCheck::class, 1);
    }

    public function test_csv_rejects_social_posts_without_expected_text(): void
    {
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_text'],
            [['Missing text', 'https://www.threads.com/@creator/post/ABC123', 'Campaign', '']],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('社群貼文必須提供 expected_text 欄位中的預期文字。');

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertSame(1, $preview->viewData('invalidCount'));
        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_csv_rejects_absent_text_mode_for_social_posts(): void
    {
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_text', 'expected_text_mode'],
            [['Absent social text', 'https://www.facebook.com/story.php?story_fbid=123', 'Campaign', 'Expected phrase', 'absent']],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('社群貼文的 expected_text_mode 必須是 present。');

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_csv_can_import_absent_text_conditions_for_regular_web_pages(): void
    {
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_text', 'expected_text_mode'],
            [['Maintenance banner', 'https://example.com/status', 'Operations', 'Scheduled maintenance', 'absent']],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('不得包含：Scheduled maintenance');

        $this->assertSame(1, $preview->viewData('validCount'));
        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $monitor = Monitor::query()->sole();
        $this->assertSame('Scheduled maintenance', $monitor->expected_text);
        $this->assertSame('absent', $monitor->expected_text_mode);
    }

    public function test_csv_rejects_invalid_text_modes_oversized_text_and_extra_cells(): void
    {
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_text', 'expected_text_mode'],
            [
                ['Invalid mode', 'https://example.com/invalid-mode', 'Ops', 'Expected', 'contains'],
                ['Long text', 'https://example.com/long-text', 'Ops', str_repeat('x', 501), 'present'],
                ['Extra cell', 'https://example.com/extra-cell', 'Ops', 'Expected', 'present', 'unexpected'],
            ],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('預期文字比對方式只能是 present 或 absent。')
            ->assertSee('預期文字不得超過 500 個字元。')
            ->assertSee('資料欄位數量與標題列不一致。');

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertSame(3, $preview->viewData('invalidCount'));
        $this->assertDatabaseCount('monitors', 0);
    }

    /** @param list<string> $headers
     * @param  list<list<string>>  $rows
     */
    private function csvContent(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv === false ? '' : $csv;
    }
}
