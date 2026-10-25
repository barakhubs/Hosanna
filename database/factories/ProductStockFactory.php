<?php

namespace Database\Factories;

use App\Models\ProductBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductStock>
 */
class ProductStockFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => 1,
            'batch_id' => ProductBatch::factory(),
            'product_id' => fn (array $attributes) => ProductBatch::find($attributes['batch_id'])->product_id,
            'quantity' => 10,
        ];
    }
}
