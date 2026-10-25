<?php

namespace Tests\Unit\Models;

use App\Models\Expense;
use App\Models\PurchaseTransaction;
use App\Models\SalaryRecord;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Transaction;
use Tests\TestCase;

class StoreAndDateScopesTest extends TestCase
{
    public static function models(): array
    {
        return [
            'sale' => [Sale::class, 'sale_date'],
            'sale item' => [SaleItem::class, 'sale_date'],
            'transaction' => [Transaction::class, 'transaction_date'],
            'purchase transaction' => [PurchaseTransaction::class, 'transaction_date'],
            'expense' => [Expense::class, 'expense_date'],
            'salary record' => [SalaryRecord::class, 'salary_date'],
        ];
    }

    /** @dataProvider models */
    public function test_store_scope(string $model, string $column): void
    {
        $this->assertStringContainsString('"store_id" = ?', $model::storeId(2)->toSql());
        $this->assertStringNotContainsString('store_id', $model::storeId('All')->toSql());
        $this->assertStringNotContainsString('store_id', $model::storeId(0)->toSql());
        // only the int 0 is skipped, the string '0' still filters
        $this->assertStringContainsString('"store_id" = ?', $model::storeId('0')->toSql());
    }

    /** @dataProvider models */
    public function test_date_scope(string $model, string $column): void
    {
        $query = $model::dateFilter('2025-01-01', '2025-01-31');
        $this->assertStringContainsString("\"{$column}\" between ? and ?", $query->toSql());
        $this->assertSame(['2025-01-01', '2025-01-31'], $query->getBindings());

        $this->assertStringNotContainsString('between', $model::dateFilter('2025-01-01', null)->toSql());
        $this->assertStringNotContainsString('between', $model::dateFilter('', '2025-01-31')->toSql());
    }
}
