<?php

namespace App\Queries;

use App\Models\Product;
use App\Support\SqlDialect;
use App\Support\StorageUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Active batches with stock in a store, shaped for the POS product grid.
 */
class PosProductQuery
{
    /**
     * @param  array  $filters  category_id (optional)
     */
    public function get(int|string $storeId, array $filters = []): Collection
    {
        $imageUrl = StorageUrl::prefix('/storage/');

        $query = Product::query()
            ->select(
                'products.id',
                DB::raw(SqlDialect::concat("'{$imageUrl}'", 'products.image_url') . " AS image_url"),
                'products.name',
                'products.is_stock_managed',
                DB::raw("COALESCE(pb.batch_number, 'N/A') AS batch_number"),
                DB::raw("COALESCE(product_stocks.quantity, 0) AS quantity"),
                DB::raw("COALESCE(product_stocks.quantity, 0) AS stock_quantity"),
                'pb.cost',
                'pb.price',
                'pb.id AS batch_id',
                'products.meta_data',
                'products.product_type',
                'products.alert_quantity',
                'pb.discount',
                'pb.discount_percentage'
            )
            ->leftJoin('product_batches AS pb', 'products.id', '=', 'pb.product_id')
            ->leftJoin('product_stocks', 'pb.id', '=', 'product_stocks.batch_id')
            ->where('pb.is_active', 1)
            ->where('product_stocks.store_id', $storeId);

        if (isset($filters['category_id'])) {
            $query->where('products.category_id', $filters['category_id']);
        }

        return $query->groupBy(
            'products.id',
            'products.image_url',
            'products.name',
            'products.discount',
            'products.is_stock_managed',
            DB::raw("COALESCE(pb.batch_number, 'N/A')"),
            'pb.cost',
            'pb.price',
            'pb.id',
            'product_stocks.quantity',
            'products.product_type',
            'products.meta_data',
            'products.alert_quantity',
            'pb.discount',
            'pb.discount_percentage'
        )->get();
    }
}
