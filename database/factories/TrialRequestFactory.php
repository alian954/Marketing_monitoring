<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\ProductPlan;
use App\Models\TrialRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrialRequest>
 */
class TrialRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'product_plan_id' => ProductPlan::factory(),
            'product_id' => fn (array $attributes): int => (int) ProductPlan::query()->findOrFail($attributes['product_plan_id'])->product_id,
            'requested_by_user_id' => null,
            'status' => TrialRequest::STATUS_PENDING,
            'message' => null,
        ];
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => TrialRequest::STATUS_REJECTED,
            'reviewed_at' => now(),
            'review_note' => '暫不開放試用。',
        ]);
    }
}
