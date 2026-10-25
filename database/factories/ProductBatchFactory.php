<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductBatch>
 */
class ProductBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'batch_number' => 'DEFAULT',
            'cost' => 60,
            'price' => 100,
            'discount' => 0,
            'is_active' => 1,
        ];
    }
}
