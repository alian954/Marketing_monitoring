<?php

namespace Database\Factories;

use App\Models\ClientReportSchedule;
use App\Models\ClientReportTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientReportSchedule>
 */
class ClientReportScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => fn (array $attributes): int => (int) ClientReportTemplate::query()
                ->whereKey($attributes['client_report_template_id'])
                ->value('client_id'),
            'client_report_template_id' => ClientReportTemplate::factory(),
            'frequency' => ClientReportSchedule::FREQUENCY_WEEKLY,
            'recipients' => fake()->safeEmail(),
            'next_run_on' => now(ClientReportSchedule::TIMEZONE)->toDateString(),
            'paused_at' => null,
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn (): array => ['frequency' => ClientReportSchedule::FREQUENCY_MONTHLY]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['paused_at' => now()]);
    }
}
