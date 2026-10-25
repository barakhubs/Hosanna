<?php

namespace Tests\Feature\Characterization;

use App\Models\CashLog;
use App\Models\Contact;
use App\Models\ProductBatch;
use App\Models\ProductStock;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseTransaction;
use App\Models\Sale;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCheckouts;
use Tests\Concerns\SeedsPos;
use Tests\TestCase;

class PurchasePaymentTest extends TestCase
{
    use MakesCheckouts;
    use RefreshDatabase;
    use SeedsPos;

    protected Contact $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPos();
        $this->actingAs($this->admin);
        $this->vendor = Contact::factory()->vendor()->create();
    }

    protected function purchase(array $cartItems, array $payments, float $netTotal)
    {
        return $this->postJson('/purchase/store', [
            'store_id' => $this->store->id,
            'contact_id' => $this->vendor->id,
            'purchase_date' => '2025-01-15',
            'net_total' => $netTotal,
            'discount' => 0,
            'reference_no' => 'REF-1',
            'cartItems' => $cartItems,
            'payments' => $payments,
        ]);
    }

    public function test_purchase_with_new_batch_adds_stock_and_records_payments(): void
    {
        $stock = $this->stockFor(['cost' => 40, 'price' => 80], 2);

        $this->purchase([[
            'id' => $stock->product_id,
            'batch_id' => null,
            'name' => 'Item',
            'batch_number' => 'B2',
            'status' => 'new',
            'product_type' => 'simple',
            'cost' => 50,
            'price' => 90,
            'quantity' => 5,
        ]], [
            ['payment_method' => 'Cash', 'amount' => 100],
            ['payment_method' => 'Credit', 'amount' => 150],
        ], 250)->assertStatus(201)->assertJson(['message' => 'Purchase created successfully!']);

        $purchase = Purchase::sole();
        $this->assertEquals(100, $purchase->amount_paid);
        $this->assertSame('pending', $purchase->status);
        $this->assertSame('pending', $purchase->payment_status);

        $batch = ProductBatch::where('batch_number', 'B2')->sole();
        $this->assertEquals(50, $batch->cost);
        $this->assertEquals(90, $batch->price);
        $this->assertEquals(5, ProductStock::where('batch_id', $batch->id)->sole()->quantity);
        $this->assertEquals(2, $stock->fresh()->quantity);

        $this->assertSame(['Cash' => 'purchase', 'Credit' => 'purchase'],
            PurchaseTransaction::pluck('transaction_type', 'payment_method')->all());

        $cashLog = CashLog::sole();
        $this->assertSame('cash_out', $cashLog->transaction_type);
        $this->assertSame('purchases', $cashLog->source);
        $this->assertEquals(-100, $cashLog->amount);

        $this->assertEquals(-150, $this->vendor->fresh()->balance);
    }

    public function test_purchase_with_existing_batch_updates_price_and_completes(): void
    {
        $stock = $this->stockFor(['cost' => 40, 'price' => 80, 'batch_number' => 'A1'], 2);

        $this->purchase([[
            'id' => $stock->product_id,
            'batch_id' => $stock->batch_id,
            'name' => 'Item',
            'batch_number' => 'A1',
            'status' => 'existing',
            'product_type' => 'simple',
            'cost' => 40,
            'price' => 95,
            'quantity' => 3,
        ]], [
            ['payment_method' => 'Account', 'amount' => 120],
        ], 120)->assertStatus(201);

        $purchase = Purchase::sole();
        $this->assertSame('completed', $purchase->status);
        $this->assertEquals(95, $stock->batch->fresh()->price);
        $this->assertEquals(5, $stock->fresh()->quantity);
        $this->assertSame('account', PurchaseTransaction::sole()->transaction_type);
        $this->assertEquals(-120, $this->vendor->fresh()->balance);
    }

    public function test_custom_item_has_no_stock(): void
    {
        $this->purchase([[
            'id' => null,
            'batch_id' => null,
            'name' => 'Transport',
            'batch_number' => null,
            'product_type' => 'custom',
            'cost' => 30,
            'price' => 30,
            'quantity' => 1,
        ]], [], 30)->assertStatus(201);

        $item = PurchaseItem::sole();
        $this->assertNull($item->product_id);
        $this->assertSame('Transport', $item->description);
        $this->assertSame(0, ProductStock::count());
    }

    public function test_missing_existing_batch_rolls_back(): void
    {
        $stock = $this->stockFor(['cost' => 40, 'batch_number' => 'A1']);

        $this->purchase([[
            'id' => $stock->product_id,
            'batch_id' => $stock->batch_id,
            'name' => 'Item',
            'batch_number' => 'A1',
            'status' => 'existing',
            'product_type' => 'simple',
            'cost' => 41,
            'price' => 80,
            'quantity' => 1,
        ]], [], 41)->assertStatus(500);

        $this->assertSame(0, Purchase::count());
    }

    public function test_customer_payment_against_a_sale(): void
    {
        $stock = $this->stockFor(['price' => 100]);
        $saleId = $this->checkout([
            'net_total' => 200,
            'cartItems' => [$this->cartItem($stock, ['quantity' => 2])],
            'payments' => [['payment_method' => 'Credit', 'amount' => 200]],
        ])->json('sale_id');

        $this->postJson('/customer-transaction', [
            'amount' => 200,
            'payment_method' => 'Cash',
            'transaction_date' => '2025-01-16',
            'contact_id' => $this->customer->id,
            'transaction_id' => $saleId,
            'store_id' => $this->store->id,
        ])->assertOk()->assertJson(['message' => 'Payment added successfully']);

        $sale = Sale::find($saleId);
        $this->assertEquals(200, $sale->amount_received);
        $this->assertSame('completed', $sale->status);
        $this->assertEquals(0, $this->customer->fresh()->balance);
        $this->assertSame(1, CashLog::count());
    }

    public function test_account_payment_is_allocated_oldest_sale_first(): void
    {
        $stock = $this->stockFor(['price' => 100]);
        $credit = fn (string $date) => $this->checkout([
            'sale_date' => $date,
            'net_total' => 100,
            'cartItems' => [$this->cartItem($stock)],
            'payments' => [['payment_method' => 'Credit', 'amount' => 100]],
        ])->json('sale_id');

        $newer = $credit('2025-01-10');
        $older = $credit('2025-01-05');
        $this->assertEquals(-200, $this->customer->fresh()->balance);

        $this->postJson('/customer-transaction', [
            'amount' => 150,
            'payment_method' => 'Cash',
            'transaction_date' => '2025-01-16',
            'contact_id' => $this->customer->id,
            'store_id' => $this->store->id,
        ])->assertOk();

        $this->assertEquals(-50, $this->customer->fresh()->balance);
        $this->assertSame('completed', Sale::find($older)->status);
        $this->assertEquals(100, Sale::find($older)->amount_received);
        $this->assertEquals(50, Sale::find($newer)->amount_received);
        $this->assertSame('pending', Sale::find($newer)->payment_status);

        $parent = Transaction::whereNull('sales_id')->sole();
        $this->assertSame('account', $parent->transaction_type);
        $children = Transaction::where('parent_id', $parent->id)->orderBy('id')->get();
        $this->assertSame([100.0, 50.0], $children->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertSame(['Account'], $children->pluck('payment_method')->unique()->values()->all());
    }
}
