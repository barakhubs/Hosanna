<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCheckouts;
use Tests\Concerns\SeedsPos;
use Tests\TestCase;

class ChargedSaleReversalTest extends TestCase
{
    use MakesCheckouts;
    use RefreshDatabase;
    use SeedsPos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPos();
        $this->actingAs($this->admin);
    }

    protected function chargedSale(): array
    {
        $stock = $this->stockFor(['price' => 100], 10);
        $saleId = $this->checkout([
            'payment_method' => 'Cash',
            'net_total' => 250,
            'cartItems' => [$this->cartItem($stock, ['quantity' => 2])],
            'charges' => [$this->charge('Delivery', 'delivery_fee', 'fixed', 50)],
        ])->assertStatus(201)->json('sale_id');

        return [$stock, $saleId];
    }

    public function test_a_sale_with_charges_can_be_deleted(): void
    {
        [$stock, $saleId] = $this->chargedSale();
        $this->assertEquals(8, $stock->fresh()->quantity);

        $this->deleteJson("/sales/{$saleId}")->assertOk();

        $this->assertEquals(10, $stock->fresh()->quantity);
        $this->assertSoftDeleted(Sale::withTrashed()->find($saleId));
        $this->assertSame(0, SaleItem::where('sale_id', $saleId)->count());
    }

    public function test_a_sale_with_charges_can_be_edited(): void
    {
        [$stock, $saleId] = $this->chargedSale();

        $this->checkout([
            'edit_sale' => true,
            'edit_sale_id' => $saleId,
            'payment_method' => 'Cash',
            'net_total' => 150,
            'cartItems' => [$this->cartItem($stock)],
            'charges' => [$this->charge('Packing', 'custom', 'fixed', 50)],
        ])->assertStatus(201);

        $this->assertEquals(9, $stock->fresh()->quantity);
        $charges = SaleItem::where('sale_id', $saleId)->where('item_type', 'charge')->pluck('description')->all();
        $this->assertSame(['Packing'], $charges);
    }
}
