<?php

namespace Database\Factories;

use App\Models\ClientReportShare;
use App\Models\ClientReportSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientReportSnapshot>
 */
class ClientReportSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $csvContent = "\xEF\xBB\xBF資料列類型,活動\n活動,示範活動\n";

        return [
            'client_report_share_id' => ClientReportShare::factory(),
            'created_by_user_id' => null,
            'report_sections' => ClientReportShare::DEFAULT_REPORT_SECTIONS,
            'report_starts_on' => now()->subDays(6)->toDateString(),
            'report_ends_on' => now()->toDateString(),
            'csv_content' => $csvContent,
            'csv_sha256' => hash('sha256', $csvContent),
        ];
    }
}
