<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LookupIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasIndex('cash_logs', ['reference_id', 'source']));
        $this->assertTrue(Schema::hasIndex('product_stocks', ['store_id', 'batch_id']));
        $this->assertTrue(Schema::hasIndex('sales', ['sale_date']));
        $this->assertTrue(Schema::hasIndex('sale_items', ['sale_date']));
        $this->assertTrue(Schema::hasIndex('transactions', ['transaction_date']));
    }

    public function test_migration_can_run_twice_and_roll_back(): void
    {
        $migration = require database_path('migrations/2026_10_25_000100_add_lookup_indexes.php');

        $migration->up();
        $this->assertTrue(Schema::hasIndex('cash_logs', 'cash_logs_reference_id_source_index'));

        $migration->down();
        $this->assertFalse(Schema::hasIndex('cash_logs', 'cash_logs_reference_id_source_index'));

        $migration->up();
        $this->assertTrue(Schema::hasIndex('cash_logs', 'cash_logs_reference_id_source_index'));
    }

    public function test_checkout_stock_lookup_uses_the_index(): void
    {
        $plan = collect(DB::select('EXPLAIN QUERY PLAN select * from product_stocks where store_id = 1 and batch_id = 1'))
            ->pluck('detail')->implode(' ');

        $this->assertStringContainsString('product_stocks_store_id_batch_id_index', $plan);
    }
}
