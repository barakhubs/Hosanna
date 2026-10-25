<?php

namespace Tests\Feature;

use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCheckouts;
use Tests\Concerns\SeedsPos;
use Tests\TestCase;

class CheckoutValidationTest extends TestCase
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

    public function test_missing_fields_are_rejected_before_anything_is_saved(): void
    {
        $this->postJson('/pos/checkout', ['payment_method' => 'Cash'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact_id', 'net_total', 'cartItems']);

        $this->assertSame(0, Sale::count());
    }

    public function test_bad_cart_lines_are_rejected(): void
    {
        $stock = $this->stockFor();
        $item = $this->cartItem($stock);
        unset($item['discount']);

        $this->checkout(['payment_method' => 'Cash', 'net_total' => 100, 'cartItems' => [$item, ['id' => 1]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cartItems.0.discount', 'cartItems.1.quantity', 'cartItems.1.price', 'cartItems.1.batch_id']);
    }

    public function test_non_cash_sale_needs_payments(): void
    {
        $stock = $this->stockFor();

        $this->checkout(['net_total' => 100, 'cartItems' => [$this->cartItem($stock)]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payments']);

        $this->checkout(['net_total' => 100, 'cartItems' => [$this->cartItem($stock)], 'payments' => [['payment_method' => 'Card']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payments.0.amount']);
    }

    public function test_charge_lines_are_checked(): void
    {
        $stock = $this->stockFor();

        $this->checkout(['payment_method' => 'Cash', 'net_total' => 100, 'cartItems' => [$this->cartItem($stock)], 'charges' => [['name' => 'VAT']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['charges.0.rate_value', 'charges.0.rate_type', 'charges.0.id']);
    }
}
