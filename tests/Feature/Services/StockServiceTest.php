<?php

namespace Tests\Feature\Services;

use App\Models\ProductStock;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsPos;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPos;

    protected StockService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPos();
        $this->service = new StockService();
    }

    public function test_deduct_reduces_and_clamps_at_zero(): void
    {
        $stock = $this->stockFor([], 5);

        $this->assertTrue($this->service->deduct($this->store->id, $stock->batch_id, 2));
        $this->assertEquals(3, $stock->fresh()->quantity);

        $this->assertTrue($this->service->deduct($this->store->id, $stock->batch_id, 10));
        $this->assertEquals(0, $stock->fresh()->quantity);
    }

    public function test_deduct_with_negative_quantity_adds_stock(): void
    {
        // returns come through as negative quantities
        $stock = $this->stockFor([], 5);

        $this->service->deduct($this->store->id, $stock->batch_id, -2);

        $this->assertEquals(7, $stock->fresh()->quantity);
    }

    public function test_deduct_reports_missing_row(): void
    {
        $this->assertFalse($this->service->deduct($this->store->id, 999, 1));
    }

    public function test_restore_adds_back_and_ignores_missing_rows(): void
    {
        $stock = $this->stockFor([], 5);

        $this->service->restore($this->store->id, $stock->batch_id, 3);
        $this->service->restore($this->store->id, 999, 3);

        $this->assertEquals(8, $stock->fresh()->quantity);
        $this->assertSame(1, ProductStock::count());
    }

    public function test_receive_creates_then_increments(): void
    {
        $stock = $this->stockFor([], 0);
        $stock->forceDelete();

        $created = $this->service->receive($this->store->id, $stock->batch_id, $stock->product_id, 4);
        $this->assertEquals(4, $created->fresh()->quantity);

        $this->service->receive($this->store->id, $stock->batch_id, $stock->product_id, 6);
        $this->assertEquals(10, $created->fresh()->quantity);
        $this->assertSame(1, ProductStock::count());
    }
}
