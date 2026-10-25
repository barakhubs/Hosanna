<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for the lookups checkout, sale edit/delete and the reports run most.
     */
    private array $indexes = [
        'cash_logs' => ['cash_logs_reference_id_source_index' => ['reference_id', 'source']],
        'product_stocks' => ['product_stocks_store_id_batch_id_index' => ['store_id', 'batch_id']],
        'sales' => ['sales_sale_date_index' => ['sale_date']],
        'sale_items' => ['sale_items_sale_date_index' => ['sale_date']],
        'transactions' => ['transactions_transaction_date_index' => ['transaction_date']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (!Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                }
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
