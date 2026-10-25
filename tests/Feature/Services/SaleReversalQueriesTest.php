<?php

namespace Tests\Feature\Services;

use App\Models\Sale;
use App\Services\Sales\SaleReversalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesCheckouts;
use Tests\Concerns\SeedsPos;
use Tests\TestCase;

class SaleReversalQueriesTest extends TestCase
{
    use MakesCheckouts;
    use RefreshDatabase;
    use SeedsPos;

    public function test_products_are_loaded_once_not_per_line(): void
    {
        $this->seedPos();
        $this->actingAs($this->admin);

        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = $this->cartItem($this->stockFor());
        }
        $saleId = $this->checkout(['payment_method' => 'Cash', 'net_total' => 500, 'cartItems' => $items])->json('sale_id');

        $productQueries = 0;
        DB::listen(function ($query) use (&$productQueries) {
            if (str_starts_with($query->sql, 'select * from "products"')) {
                $productQueries++;
            }
        });

        DB::transaction(fn () => app(SaleReversalService::class)->resetForEdit(Sale::find($saleId)));

        $this->assertSame(1, $productQueries);
    }
}
