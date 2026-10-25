<?php

namespace Tests\Feature\Characterization;

use App\Models\CashLog;
use App\Models\Contact;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesCheckouts;
use Tests\Concerns\SeedsPos;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use MakesCheckouts;
    use RefreshDatabase;
    use SeedsPos;

    protected int $baseTransactionLevel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPos();
        $this->actingAs($this->admin);
        $this->baseTransactionLevel = DB::transactionLevel();
    }

    public function test_cash_sale_completes_and_logs_cash(): void
    {
        $stock = $this->stockFor(['price' => 100, 'cost' => 60], 10);

        $response = $this->checkout([
            'payment_method' => 'Cash',
            'net_total' => 200,
            'amount_received' => 250,
            'profit_amount' => 80,
            'cartItems' => [$this->cartItem($stock, ['quantity' => 2])],
        ]);

        $response->assertStatus(201)->assertJson(['message' => 'Sale recorded successfully!']);

        $sale = Sale::findOrFail($response->json('sale_id'));
        $this->assertSame('completed', $sale->status);
        $this->assertSame('completed', $sale->payment_status);
        $this->assertEquals(250, $sale->amount_received);
        $this->assertEquals(200, $sale->total_amount);
        $this->assertSame('sale', $sale->sale_type);
        $this->assertSame('2025/'.str_pad($sale->id, 4, '0', STR_PAD_LEFT).'/0001', $sale->invoice_number);
        $this->assertEquals(1, $this->store->fresh()->current_sale_number);

        $transaction = Transaction::where('sales_id', $sale->id)->sole();
        $this->assertEquals(200, $transaction->amount);
        $this->assertSame('sale', $transaction->transaction_type);

        $cashLog = CashLog::where('reference_id', $transaction->id)->sole();
        $this->assertSame('cash_in', $cashLog->transaction_type);
        $this->assertSame('sales', $cashLog->source);
        $this->assertEquals(200, $cashLog->amount);

        $item = SaleItem::where('sale_id', $sale->id)->sole();
        $this->assertEquals(2, $item->quantity);
        $this->assertEquals(100, $item->unit_price);
        $this->assertEquals(60, $item->unit_cost);

        $this->assertEquals(8, $stock->fresh()->quantity);
    }

    public function test_invoice_sequence_increments_per_store(): void
    {
        $stock = $this->stockFor();

        $first = $this->checkout(['payment_method' => 'Cash', 'net_total' => 100, 'cartItems' => [$this->cartItem($stock)]]);
        $second = $this->checkout(['payment_method' => 'Cash', 'net_total' => 100, 'cartItems' => [$this->cartItem($stock)]]);

        $this->assertStringEndsWith('/0001', Sale::find($first->json('sale_id'))->invoice_number);
        $this->assertStringEndsWith('/0002', Sale::find($second->json('sale_id'))->invoice_number);
        $this->assertEquals(2, $this->store->fresh()->current_sale_number);
    }

    public function test_multi_payment_with_account_and_credit(): void
    {
        $stock = $this->stockFor(['price' => 100], 10);
        $this->customer->update(['balance' => 500]);

        $response = $this->checkout([
            'net_total' => 300,
            'cartItems' => [$this->cartItem($stock, ['quantity' => 3])],
            'payments' => [
                ['payment_method' => 'Cash', 'amount' => 100],
                ['payment_method' => 'Account', 'amount' => 120],
                ['payment_method' => 'Credit', 'amount' => 80],
            ],
        ]);

        $response->assertStatus(201);
        $sale = Sale::findOrFail($response->json('sale_id'));

        // Credit does not count as received, so the sale stays pending
        $this->assertEquals(220, $sale->amount_received);
        $this->assertSame('pending', $sale->status);
        $this->assertSame('pending', $sale->payment_status);

        $types = Transaction::where('sales_id', $sale->id)->pluck('transaction_type', 'payment_method')->all();
        $this->assertSame(['Cash' => 'sale', 'Account' => 'account', 'Credit' => 'sale'], $types);

        // Account and credit both reduce the contact balance
        $this->assertEquals(300, $this->customer->fresh()->balance);
        $this->assertSame(1, CashLog::count());
    }

    public function test_payments_covering_total_complete_the_sale(): void
    {
        $stock = $this->stockFor(['price' => 100]);

        $response = $this->checkout([
            'net_total' => 100,
            'cartItems' => [$this->cartItem($stock)],
            'payments' => [['payment_method' => 'Card', 'amount' => 100]],
        ]);

        $sale = Sale::findOrFail($response->json('sale_id'));
        $this->assertSame('completed', $sale->status);
        $this->assertSame(0, CashLog::count());
    }

    public function test_free_quantity_is_deducted_from_stock(): void
    {
        $stock = $this->stockFor([], 10);

        $this->checkout([
            'payment_method' => 'Cash',
            'net_total' => 200,
            'cartItems' => [$this->cartItem($stock, ['quantity' => 2, 'free_quantity' => 1])],
        ])->assertStatus(201);

        $this->assertEquals(7, $stock->fresh()->quantity);
    }

    public function test_stock_is_clamped_at_zero(): void
    {
        $stock = $this->stockFor([], 1);

        $this->checkout([
            'payment_method' => 'Cash',
            'net_total' => 500,
            'cartItems' => [$this->cartItem($stock, ['quantity' => 5])],
        ])->assertStatus(201);

        $this->assertEquals(0, $stock->fresh()->quantity);
    }

    public function test_unmanaged_stock_is_not_touched(): void
    {
        $stock = $this->stockFor([], 3);

        $this->checkout([
            'payment_method' => 'Cash',
            'net_total' => 100,
            'cartItems' => [$this->cartItem($stock, ['is_stock_managed' => 0])],
        ])->assertStatus(201);

        $this->assertEquals(3, $stock->fresh()->quantity);
    }

    public function test_missing_stock_row_rolls_back(): void
    {
        $stock = $this->stockFor();
        $item = $this->cartItem($stock);
        $stock->forceDelete();

        $this->checkout(['payment_method' => 'Cash', 'net_total' => 100, 'cartItems' => [$item]])
            ->assertStatus(500)
            ->assertJson(['error' => 'Stock for product not found in the specified store or batch']);

        $this->assertSame(0, Sale::withTrashed()->count());
        $this->assertSame(0, Transaction::count());
    }

    public function test_reload_without_account_number_is_rejected(): void
    {
        $stock = $this->stockFor([], 10, ['product_type' => 'reload']);

        $this->checkout([
            'payment_method' => 'Cash',
            'net_total' => 100,
            'cartItems' => [$this->cartItem($stock, ['product_type' => 'reload', 'is_stock_managed' => 0])],
        ])
            ->assertStatus(400)
            ->assertJson(['error' => 'Account number is required for reload product type.']);

        // The early return skips the rollback and leaves the transaction open
        $this->assertSame($this->baseTransactionLevel + 1, DB::transactionLevel());
        while (DB::transactionLevel() > $this->baseTransactionLevel) {
            DB::rollBack();
        }
    }

    public function test_reload_item_stores_meta(): void
    {
        $stock = $this->stockFor([], 10, ['product_type' => 'reload']);

        $response = $this->checkout([
            'payment_method' => 'Cash',
            'net_total' => 100,
            'cartItems' => [$this->cartItem($stock, [
                'product_type' => 'reload',
                'is_stock_managed' => 0,
                'account_number' => '0772000000',
                'commission' => 2,
                'additional_commission' => 1,
            ])],
        ])->assertStatus(201);

        $item = SaleItem::where('sale_id', $response->json('sale_id'))->sole();
        $this->assertDatabaseHas('reload_and_bill_metas', [
            'sale_item_id' => $item->id,
            'transaction_type' => 'reload',
            'account_number' => '0772000000',
            'description' => 'reload',
        ]);
    }

    public function test_charges_are_applied_on_discounted_subtotal(): void
    {
        $stock = $this->stockFor(['price' => 100], 10);

        $response = $this->checkout([
            'payment_method' => 'Cash',
            'net_total' => 228,
            'discount' => 20,
            'cartItems' => [$this->cartItem($stock, ['quantity' => 2, 'discount' => 5, 'flat_discount' => 10])],
            'charges' => [
                $this->charge('VAT', 'tax', 'percentage', 10),
                $this->charge('Delivery', 'delivery_fee', 'fixed', 50),
            ],
        ])->assertStatus(201);

        $sale = Sale::findOrFail($response->json('sale_id'));
        // line total: 2 * (100 - 5) - 10 = 180, base: 180 - 20 = 160
        $charges = SaleItem::where('sale_id', $sale->id)->where('item_type', 'charge')->orderBy('id')->get();
        $this->assertCount(2, $charges);
        $this->assertEquals(16, $charges[0]->unit_price);
        $this->assertEquals(160, $charges[0]->base_amount);
        $this->assertSame('VAT', $charges[0]->description);
        $this->assertEquals(50, $charges[1]->unit_price);
        $this->assertEquals(66, $sale->total_charge_amount);
    }

    public function test_return_reverses_charges_proportionally(): void
    {
        $stock = $this->stockFor(['price' => 100], 10);

        $original = $this->checkout([
            'payment_method' => 'Cash',
            'net_total' => 250,
            'cartItems' => [$this->cartItem($stock, ['quantity' => 2])],
            'charges' => [$this->charge('Delivery', 'delivery_fee', 'fixed', 50)],
        ])->json('sale_id');

        $response = $this->checkout([
            'payment_method' => 'Cash',
            'net_total' => -100,
            'return_sale' => true,
            'return_sale_id' => $original,
            'cartItems' => [$this->cartItem($stock, ['quantity' => -1])],
        ])->assertStatus(201);

        $return = Sale::findOrFail($response->json('sale_id'));
        $this->assertSame('return', $return->sale_type);
        $this->assertEquals($original, $return->reference_id);

        // Returned quantity is negative, so the reversal row ends up positive (current behaviour)
        $reversal = SaleItem::where('sale_id', $return->id)->where('item_type', 'charge')->sole();
        $this->assertEquals(25, $reversal->unit_price);
        $this->assertSame('Delivery (Reversal)', $reversal->description);
        $this->assertEquals(25, $return->total_charge_amount);

        $this->assertEquals(9, $stock->fresh()->quantity);

        $refund = Transaction::where('sales_id', $return->id)->sole();
        $this->assertSame('cash_out', CashLog::where('reference_id', $refund->id)->sole()->transaction_type);
    }

    public function test_walk_in_balance_unchanged_on_cash_sale(): void
    {
        $stock = $this->stockFor();

        $this->checkout(['payment_method' => 'Cash', 'net_total' => 100, 'cartItems' => [$this->cartItem($stock)]]);

        $this->assertEquals(0, Contact::find($this->customer->id)->balance);
    }
}
