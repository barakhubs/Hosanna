<?php

namespace Tests\Feature;

use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_a_bad_reload_does_not_swallow_the_next_sale(): void
    {
        $reload = $this->stockFor([], 10, ['product_type' => 'reload']);
        $normal = $this->stockFor(['price' => 100], 10);

        $sale = fn (string $invoice, array $item) => [
            'invoice_number' => $invoice,
            'contact_id' => $this->customer->id,
            'total_amount' => 100,
            'items' => [$item],
            'transactions' => [['payment_method' => 'Cash', 'amount' => 100]],
        ];

        $this->postJson('/api/sync?table=sales', [
            'store_id' => 1,
            'sales' => [
                $sale('OFF-1', ['product_id' => $reload->product_id, 'batch_id' => $reload->batch_id, 'quantity' => 1, 'price' => 100, 'cost' => 90, 'product_type' => 'reload', 'is_stock_managed' => 0]),
                $sale('OFF-2', ['product_id' => $normal->product_id, 'batch_id' => $normal->batch_id, 'quantity' => 1, 'price' => 100, 'cost' => 60]),
            ],
        ])->assertOk()->assertJson(['status' => 'partial', 'synced' => 1]);

        // only the test's own wrapping transaction is left open
        $this->assertSame(1, DB::transactionLevel());
        $this->assertSame(1, Sale::count());
        $this->assertEquals(9, $normal->fresh()->quantity);
    }
}
