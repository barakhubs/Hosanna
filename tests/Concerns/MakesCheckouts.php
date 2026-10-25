<?php

namespace Tests\Concerns;

use App\Models\Charge;
use App\Models\ProductStock;

trait MakesCheckouts
{
    protected function cartItem(ProductStock $stock, array $overrides = []): array
    {
        $batch = $stock->batch;

        return array_merge([
            'id' => $stock->product_id,
            'batch_id' => $batch->id,
            'quantity' => 1,
            'price' => (float) $batch->price,
            'cost' => (float) $batch->cost,
            'discount' => 0,
            'flat_discount' => 0,
            'is_stock_managed' => 1,
            'product_type' => 'simple',
        ], $overrides);
    }

    protected function charge(string $name, string $type, string $rateType, float $rate): array
    {
        $charge = Charge::create([
            'name' => $name,
            'charge_type' => $type,
            'rate_type' => $rateType,
            'rate_value' => $rate,
            'is_active' => true,
        ]);

        return [
            'id' => $charge->id,
            'name' => $name,
            'charge_type' => $type,
            'rate_type' => $rateType,
            'rate_value' => $rate,
        ];
    }

    protected function checkout(array $payload)
    {
        return $this->postJson('/pos/checkout', array_merge([
            'contact_id' => $this->customer->id,
            'sale_date' => '2025-01-15',
            'discount' => 0,
            'charges' => [],
        ], $payload));
    }
}
