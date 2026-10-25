<?php

namespace Tests\Feature;

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCheckouts;
use Tests\Concerns\SeedsPos;
use Tests\TestCase;

class ErrorResponseTest extends TestCase
{
    use MakesCheckouts;
    use RefreshDatabase;
    use SeedsPos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPos();
        $this->actingAs($this->admin);
        config(['app.debug' => false]);
    }

    public function test_database_errors_are_not_sent_to_the_client(): void
    {
        $stock = $this->stockFor();

        // contact does not exist, so the insert fails on the foreign key
        $response = $this->checkout([
            'contact_id' => 9999,
            'payment_method' => 'Cash',
            'net_total' => 100,
            'cartItems' => [$this->cartItem($stock)],
        ]);

        $response->assertStatus(500)->assertExactJson(['error' => 'Something went wrong. Please try again.']);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function test_our_own_error_messages_are_kept(): void
    {
        $stock = $this->stockFor(['cost' => 40, 'batch_number' => 'A1']);

        $this->postJson('/purchase/store', [
            'store_id' => $this->store->id,
            'contact_id' => Contact::factory()->vendor()->create()->id,
            'purchase_date' => '2025-01-15',
            'net_total' => 41,
            'reference_no' => 'R',
            'cartItems' => [[
                'id' => $stock->product_id, 'batch_id' => $stock->batch_id, 'name' => 'Item',
                'batch_number' => 'A1', 'status' => 'existing', 'product_type' => 'simple',
                'cost' => 41, 'price' => 80, 'quantity' => 1,
            ]],
            'payments' => [],
        ])->assertStatus(500)->assertExactJson(['error' => 'Batch not found for item: Item']);
    }

    public function test_debug_mode_shows_the_real_message(): void
    {
        config(['app.debug' => true]);
        $stock = $this->stockFor();

        $this->checkout([
            'contact_id' => 9999,
            'payment_method' => 'Cash',
            'net_total' => 100,
            'cartItems' => [$this->cartItem($stock)],
        ])->assertStatus(500)->assertJsonPath('error', fn ($m) => str_contains($m, 'FOREIGN KEY'));
    }
}
