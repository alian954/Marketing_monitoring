<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_code' => $this->faker->unique()->bothify('test-product-#####'),
            'name' => $this->faker->words(2, true),
            'version' => '0.1.0',
            'manifest_version' => 1,
            'lifecycle' => 'active',
            'entry_route' => 'products.index',
        ];
    }
}
