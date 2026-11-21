<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MonitorSloTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_monitor_persists_the_selected_slo_target(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('monitors.store'), [
                'name' => 'SLO website',
                'url' => 'https://fixture.test/slo',
                'slo_target_basis_points' => 9950,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $monitor = Monitor::query()->sole();

        $this->assertSame(9950, $monitor->slo_target_basis_points);
    }

    public function test_monitor_form_rejects_an_unsupported_slo_target(): void
    {
        Config::set('monitoring.transport', 'fixture');

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->post(route('monitors.store'), [
                'name' => 'Invalid SLO website',
                'url' => 'https://fixture.test/invalid-slo',
                'slo_target_basis_points' => 9951,
            ])
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors([
                'slo_target_basis_points' => '請選擇支援的可用率目標。',
            ]);

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_monitor_detail_shows_the_target_and_waits_for_observations(): void
    {
        $monitor = Monitor::factory()->create(['slo_target_basis_points' => 9950]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('服務可用率目標與 30 天錯誤預算')
            ->assertSee('99.50%')
            ->assertSee('尚無可用觀測');
        $sloBudget = $response->viewData('sloBudget');

        $this->assertSame(216.0, $sloBudget['allowed_downtime_minutes']);
        $this->assertNull($sloBudget['observed_downtime_minutes']);
        $this->assertNull($sloBudget['remaining_error_budget_minutes']);
    }

    public function test_editing_a_monitor_persists_the_new_slo_target(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $url = 'https://fixture.test/existing-slo';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'slo_target_basis_points' => 9900,
        ]);

        $this->actingAs(User::factory()->create())
            ->put(route('monitors.update', $monitor), [
                'name' => $monitor->name,
                'url' => '',
                'slo_target_basis_points' => 9995,
            ])
            ->assertRedirect(route('monitors.show', $monitor))
            ->assertSessionHasNoErrors();

        $this->assertSame(9995, $monitor->fresh()->slo_target_basis_points);
    }

    public function test_csv_import_restores_the_slo_target_and_monitor_export_includes_it(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $user = User::factory()->create();
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'interval_seconds', 'slo_target_basis_points'],
            [['Imported SLO website', 'https://fixture.test/imported-slo', '', '900', '9995']],
        );
        $preview = $this->actingAs($user)
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('99.95%');

        $this->post(route('monitors.import.commit'), [
            'preview_token' => $preview->viewData('previewToken'),
        ])->assertRedirect(route('monitors.index'));

        $monitor = Monitor::query()->sole();
        $this->assertSame(9995, $monitor->slo_target_basis_points);

        $export = $this->get(route('monitors.export'))->assertOk()->streamedContent();

        $this->assertStringContainsString('slo_target_basis_points', $export);
        $this->assertStringContainsString('9995', $export);
    }

    /**
     * @param  list<string>  $headers
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
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content;
    }
}
