<?php

namespace Tests\Feature\Characterization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\MakesCheckouts;
use Tests\Concerns\SeedsPos;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use MakesCheckouts;
    use RefreshDatabase;
    use SeedsPos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2025-01-15 10:30:00');
        $this->seedPos();
        $this->admin->update(['name' => 'Cashier One']);
        $this->actingAs($this->admin);
    }

    protected function makeSale(): int
    {
        $a = $this->stockFor(['price' => 100, 'cost' => 60], 10, ['name' => 'Sugar 1kg']);
        $b = $this->stockFor(['price' => 2500, 'cost' => 2000], 10, ['name' => 'A very long product name that has to wrap onto lines']);
        $c = $this->stockFor(['price' => 50, 'cost' => 50], 10, ['name' => 'Airtime', 'product_type' => 'reload']);

        return $this->checkout([
            'payment_method' => 'Cash',
            'net_total' => 2790,
            'discount' => 10,
            'amount_received' => 3000,
            'cartItems' => [
                $this->cartItem($a, ['quantity' => 3, 'discount' => 5, 'free_quantity' => 1]),
                $this->cartItem($b, ['quantity' => 1, 'flat_discount' => 20]),
                $this->cartItem($c, ['product_type' => 'reload', 'is_stock_managed' => 0, 'account_number' => '0772000000', 'commission' => 0, 'additional_commission' => 0]),
            ],
            'charges' => [$this->charge('VAT', 'tax', 'percentage', 10)],
        ])->assertStatus(201)->json('sale_id');
    }

    protected function assertMatchesSnapshot(string $name, string $actual): void
    {
        $path = __DIR__."/snapshots/{$name}";
        if (! file_exists($path)) {
            file_put_contents($path, $actual);
            $this->markTestIncomplete("Snapshot {$name} created");
        }
        $this->assertSame(file_get_contents($path), $actual);
    }

    public function test_plain_text_receipt(): void
    {
        $saleId = $this->makeSale();

        $response = $this->get("/api/receipt-text-raw/{$saleId}");

        $response->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertMatchesSnapshot('receipt-text.txt', $response->getContent());
    }

    public function test_json_receipt(): void
    {
        $saleId = $this->makeSale();

        $this->getJson("/api/receipt-text-raw/{$saleId}?isJson=1")
            ->assertOk()
            ->assertJsonPath('siteLogo', 'public/logo.png')
            ->assertJsonPath('sale.invoice_number', '2025/0001/0001')
            ->assertJsonPath('sale.client_name', 'Walk-in');
    }

    public function test_receipt_page_props(): void
    {
        $saleId = $this->makeSale();

        $this->get("/receipt/{$saleId}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Sale/Receipt')
                ->where('user_name', $this->admin->name)
                ->where('settings.shop_logo', 'logo.png')
                ->where('sale.invoice_number', '2025/0001/0001')
                ->where('sale.name', 'Walk-in')
                ->has('salesItems', 4)
                ->where('salesItems.2.account_number', '0772000000')
                ->where('salesItems.3.item_type', 'charge')
            );
    }

    public function test_receipt_for_missing_sale_is_404(): void
    {
        $this->get('/receipt/999')->assertNotFound();
        $this->getJson('/api/receipt-text-raw/999')->assertNotFound()->assertJson(['error' => 'Sale not found']);
    }

    public function test_pending_sales_receipt_merges_credit_sales(): void
    {
        $stock = $this->stockFor(['price' => 100]);
        foreach ([1, 2] as $qty) {
            $this->checkout([
                'net_total' => 100 * $qty,
                'cartItems' => [$this->cartItem($stock, ['quantity' => $qty])],
                'payments' => [['payment_method' => 'Credit', 'amount' => 100 * $qty]],
            ]);
        }

        $this->get("/pending-sales-receipt/{$this->customer->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Sale/Receipt')
                ->where('credit_sale', true)
                ->where('sale.id', 'merged')
                ->where('sale.total_amount', 300)
                ->where('sale.name', 'Walk-in | Balance: -300')
                ->has('salesItems', 2)
                ->where('salesItems.0.name', ' [2025-01-15] - '.\App\Models\Product::find($stock->product_id)->name)
            );
    }

    public function test_pending_sales_receipt_without_sales(): void
    {
        $this->get("/pending-sales-receipt/{$this->customer->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sale', '')
                ->where('salesItems', '')
                ->where('credit_sale', true)
            );
    }
}
