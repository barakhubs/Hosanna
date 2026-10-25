<?php

namespace App\Queries;

use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The joins every receipt needs. Callers pick the columns they show.
 */
class SaleReceiptQuery
{
    /**
     * Sales with their customer (contacts.*) and store (stores.*).
     */
    public function sales(array $columns): Builder
    {
        return Sale::select($columns)
            ->leftJoin('contacts', 'sales.contact_id', '=', 'contacts.id')
            ->join('stores', 'sales.store_id', '=', 'stores.id');
    }

    /**
     * Sale lines with their product (products.*), plus the account number
     * for reload items as account_number.
     */
    public function items(array $columns): Builder
    {
        $columns[] = DB::raw("CASE
                WHEN products.product_type = 'reload'
                THEN reload_and_bill_metas.account_number
                ELSE NULL
             END as account_number");

        return SaleItem::select($columns)
            ->leftJoin('products', 'sale_items.product_id', '=', 'products.id')
            ->leftJoin('reload_and_bill_metas', function ($join) {
                $join->on('sale_items.id', '=', 'reload_and_bill_metas.sale_item_id')
                    ->where('products.product_type', '=', 'reload');
            });
    }
}
