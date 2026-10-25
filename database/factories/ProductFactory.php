<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'barcode' => (string) fake()->unique()->numberBetween(100000, 999999),
            'unit' => 'pc',
            'alert_quantity' => 5,
            'is_stock_managed' => 1,
            'is_active' => 1,
            'product_type' => 'simple',
        ];
    }
}
