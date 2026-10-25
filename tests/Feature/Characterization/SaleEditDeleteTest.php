<?php

namespace Tests\Feature\Characterization;

use App\Models\CashLog;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCheckouts;
use Tests\Concerns\SeedsPos;
use Tests\TestCase;

class SaleEditDeleteTest extends TestCase
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

    public function test_edit_sale_restores_stock_and_balances_before_reapplying(): void
    {
        $stock = $this->stockFor(['price' => 100], 10);
        $this->customer->update(['balance' => 0]);

        $saleId = $this->checkout([
            'net_total' => 300,
            'cartItems' => [$this->cartItem($stock, ['quantity' => 3, 'free_quantity' => 1])],
            'payments' => [
                ['payment_method' => 'Cash', 'amount' => 100],
                ['payment_method' => 'Credit', 'amount' => 200],
            ],
        ])->json('sale_id');

        $this->assertEquals(6, $stock->fresh()->quantity);
        $this->assertEquals(-200, $this->customer->fresh()->balance);
        $oldCashTransaction = Transaction::where('sales_id', $saleId)->where('payment_method', 'Cash')->sole();

        $response = $this->checkout([
            'edit_sale' => true,
            'edit_sale_id' => $saleId,
            'payment_method' => 'Cash',
            'net_total' => 100,
            'cartItems' => [$this->cartItem($stock, ['quantity' => 1])],
        ])->assertStatus(201);

        $this->assertEquals($saleId, $response->json('sale_id'));
        $this->assertSame(1, Sale::count());

        // 6 + 3 + 1 restored, then 1 sold
        $this->assertEquals(9, $stock->fresh()->quantity);
        $this->assertEquals(0, $this->customer->fresh()->balance);

        $this->assertSame(0, CashLog::where('reference_id', $oldCashTransaction->id)->count());
        $this->assertSame(1, Transaction::where('sales_id', $saleId)->count());
        $this->assertSame(1, SaleItem::where('sale_id', $saleId)->count());
        $this->assertSame(1, SaleItem::onlyTrashed()->where('sale_id', $saleId)->count());
        $this->assertSame('completed', Sale::find($saleId)->status);
    }

    protected function mixedPaymentSale($stock): int
    {
        $this->customer->update(['balance' => 0]);

        return $this->checkout([
            'net_total' => 300,
            'cartItems' => [$this->cartItem($stock, ['quantity' => 3, 'free_quantity' => 1])],
            'payments' => [
                ['payment_method' => 'Cash', 'amount' => 100],
                ['payment_method' => 'Account', 'amount' => 50],
                ['payment_method' => 'Credit', 'amount' => 150],
            ],
        ])->json('sale_id');
    }

    public function test_delete_restores_quantity_and_reverses_cash_and_credit_only(): void
    {
        $stock = $this->stockFor(['price' => 100], 10);
        $saleId = $this->mixedPaymentSale($stock);

        $this->assertEquals(6, $stock->fresh()->quantity);
        $this->assertEquals(-200, $this->customer->fresh()->balance);

        $this->deleteJson("/sales/{$saleId}")
            ->assertOk()
            ->assertJson(['success' => 'Sale deleted successfully']);

        // free_quantity is not put back on delete (edit does put it back)
        $this->assertEquals(9, $stock->fresh()->quantity);
        // only the credit part is reversed, the account payment is not
        $this->assertEquals(-50, $this->customer->fresh()->balance);
        $this->assertSame(0, CashLog::count());
        $this->assertSame(0, Transaction::where('sales_id', $saleId)->count());
        $this->assertSame(0, SaleItem::where('sale_id', $saleId)->count());

        $sale = Sale::withTrashed()->find($saleId);
        $this->assertNotNull($sale->deleted_at);
        $this->assertEquals($this->admin->id, $sale->deleted_by);
    }

    public function test_only_admin_role_can_delete(): void
    {
        $stock = $this->stockFor();
        $saleId = $this->checkout(['payment_method' => 'Cash', 'net_total' => 100, 'cartItems' => [$this->cartItem($stock)]])->json('sale_id');

        foreach (['user', 'super-admin'] as $role) {
            $this->actingAs($this->makeUser($role))
                ->deleteJson("/sales/{$saleId}")
                ->assertStatus(403)
                ->assertJson(['error' => 'You are not authorized to delete a sale']);
        }

        $this->assertNotNull(Sale::find($saleId));
    }

    public function test_only_sales_created_today_can_be_deleted(): void
    {
        $stock = $this->stockFor();
        $saleId = $this->checkout(['payment_method' => 'Cash', 'net_total' => 100, 'cartItems' => [$this->cartItem($stock)]])->json('sale_id');
        Sale::whereKey($saleId)->update(['created_at' => now()->subDay()]);

        $this->deleteJson("/sales/{$saleId}")
            ->assertStatus(403)
            ->assertJson(['error' => 'Only sales created today can be deleted']);
    }

    public function test_delete_missing_sale_returns_404(): void
    {
        $this->deleteJson('/sales/999')->assertStatus(404)->assertJson(['error' => 'Sale not found']);
    }
}
