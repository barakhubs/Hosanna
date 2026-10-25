<?php

namespace Tests\Feature;

use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsPos;
use Tests\TestCase;

class SyncSalesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPos();
        Sanctum::actingAs($this->admin);
    }

    public function test_pushed_sale_goes_through_checkout(): void
    {
        $stock = $this->stockFor(['price' => 100, 'cost' => 60], 10);

        $this->postJson('/api/sync?table=sales', [
            'store_id' => 1,
            'sales' => [[
                'invoice_number' => 'OFF-1',
                'contact_id' => $this->customer->id,
                'sale_date' => '2025-01-15',
                'total_amount' => 200,
                'items' => [[
                    'product_id' => $stock->product_id,
                    'batch_id' => $stock->batch_id,
                    'quantity' => 2,
                    'unit_price' => 100,
                    'unit_cost' => 60,
                    'product_type' => 'simple',
                ]],
                'transactions' => [['payment_method' => 'Cash', 'amount' => 200]],
            ]],
        ])->assertOk()->assertJson(['status' => 'success', 'synced' => 1]);

        $sale = Sale::sole();
        $this->assertEquals(200, $sale->total_amount);
        $this->assertSame('completed', $sale->status);
        $this->assertEquals(8, $stock->fresh()->quantity);
    }

    public function test_failed_sale_is_reported(): void
    {
        $stock = $this->stockFor();
        $batchId = $stock->batch_id;
        $productId = $stock->product_id;
        $stock->forceDelete();

        $this->postJson('/api/sync?table=sales', [
            'store_id' => 1,
            'sales' => [[
                'invoice_number' => 'OFF-2',
                'contact_id' => $this->customer->id,
                'total_amount' => 100,
                'items' => [['product_id' => $productId, 'batch_id' => $batchId, 'quantity' => 1, 'price' => 100, 'cost' => 60]],
                'transactions' => [['payment_method' => 'Cash', 'amount' => 100]],
            ]],
        ])->assertOk()->assertJson([
            'status' => 'partial',
            'synced' => 0,
            'errors' => [['invoice_number' => 'OFF-2', 'error' => 'Stock for product not found in the specified store or batch']],
        ]);
    }
}
