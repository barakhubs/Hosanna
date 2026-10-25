<?php

namespace Tests\Feature\Characterization;

use App\Models\Charge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\SeedsPos;
use Tests\TestCase;

class PosPagesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPos();
        $this->actingAs($this->admin);
    }

    public function test_pos_page_lists_every_active_product_in_store(): void
    {
        // no limit is applied even though the code tries to cap at 20
        for ($i = 0; $i < 22; $i++) {
            $this->stockFor(['price' => 100 + $i], 5, ['image_url' => "img{$i}.png"]);
        }
        $this->stockFor(['is_active' => 0]);
        Charge::create(['name' => 'VAT', 'charge_type' => 'tax', 'rate_type' => 'percentage', 'rate_value' => 18, 'is_active' => true, 'is_default' => true]);
        Charge::create(['name' => 'Tip', 'charge_type' => 'gratuity', 'rate_type' => 'fixed', 'rate_value' => 5, 'is_active' => true, 'is_default' => false]);

        $this->get('/pos')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('POS/POS')
                ->has('products', 22)
                ->where('products.0.image_url', '/storage/img0.png')
                ->where('products.0.quantity', 5)
                ->where('currentStore', $this->store->name)
                ->where('return_sale', false)
                ->where('cart_first_focus', 'quantity')
                ->has('default_charges', 1)
                ->where('default_charges.0.name', 'VAT')
                ->has('customers', 1)
            );
    }

    public function test_product_search_matches_barcode_prefix_and_name(): void
    {
        $this->stockFor([], 4, ['name' => 'Blue Pen', 'barcode' => '555123']);
        $this->stockFor([], 4, ['name' => 'Red Pencil', 'barcode' => '777000']);

        $this->getJson('/products/search?search_query=555')
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.name', 'Blue Pen')
            ->assertJsonPath('products.0.image_url', null);

        $this->getJson('/products/search?search_query=Pen')->assertJsonCount(2, 'products');
    }

    public function test_shared_inertia_props(): void
    {
        $this->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('settings.shop_name', 'Test Shop')
                ->where('settings.currency_settings.currency_symbol', 'UGX')
                ->where('modules', ['Inventory'])
                ->has('userPermissions', 19)
            );
    }
}
