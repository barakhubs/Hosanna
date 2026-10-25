<?php

namespace App\Services\Sales;

use App\Models\CashLog;
use App\Models\Contact;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Transaction;
use App\Services\Inventory\StockService;

/**
 * Undoes a sale's effect on stock, balances and the cash drawer, either before
 * it is saved again from the till (edit) or when it is deleted.
 * The two have always used slightly different rules, kept as they are here.
 */
class SaleReversalService
{
    public function __construct(private StockService $stock)
    {
    }

    /**
     * Before an edit: put back sold and free quantities, reverse account and
     * credit payments, and clear the items and payments.
     */
    public function resetForEdit(Sale $sale): void
    {
        $sale->loadMissing('saleItems.product');

        foreach ($sale->saleItems as $item) {
            $product = $item->product;

            if ($product->is_stock_managed) {
                $restoreQty = $item->quantity + ($item->free_quantity ?? 0);
                $this->stock->restore($sale->store_id, $item->batch_id, $restoreQty);
            }
        }

        foreach ($sale->transactions as $transaction) {
            if (in_array($transaction->payment_method, ['Account', 'Credit'])) {
                Contact::where('id', $sale->contact_id)->increment('balance', $transaction->amount);
            }

            if ($transaction->payment_method == 'Cash') {
                CashLog::where('reference_id', $transaction->id)->where('source', 'sales')->delete();
            }
        }

        SaleItem::where('sale_id', $sale->id)->delete();
        Transaction::where('sales_id', $sale->id)->delete();
    }

    /**
     * Delete a sale: put back the sold quantity (not free items) and reverse
     * credit payments only.
     */
    public function delete(Sale $sale, $userId): void
    {
        $sale->loadMissing('saleItems.product');

        foreach ($sale->saleItems as $item) {
            $product = $item->product;

            if ($product->is_stock_managed) {
                $this->stock->restore($sale->store_id, $item->batch_id, $item->quantity);
            }
            $item->delete();
        }

        foreach ($sale->transactions as $transaction) {
            if ($transaction->payment_method == 'Cash') {
                CashLog::where('reference_id', $transaction->id)->where('source', 'sales')->delete();
            } elseif ($transaction->payment_method == 'Credit') {
                Contact::where('id', $sale->contact_id)->increment('balance', $transaction['amount']);
            }
            $transaction->delete();
        }

        $sale->deleted_by = $userId;
        $sale->save();
        $sale->delete();
    }
}
