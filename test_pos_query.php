#!/usr/bin/env php
<?php

// Simple test script to verify POS products query
require __DIR__ . '/bootstrap/app.php';

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductStock;
use Illuminate\Support\Facades\DB;

$app = \Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (\Illuminate\Foundation\Configuration\Middleware $middleware) {
        //
    })
    ->withExceptions(function (\Illuminate\Foundation\Configuration\Exceptions $exceptions) {
        //
    })->create();

$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Testing POS Products Query...\n";

// Test the exact query used in POSController
$imageUrl = '/storage/';
$currentStoreId = 1;

$query = Product::select(
    'products.id',
    DB::raw("'{$imageUrl}' || products.image_url AS image_url"),
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
    ->where('product_stocks.store_id', $currentStoreId);

echo "SQL Query: " . $query->toSql() . "\n";
echo "Bindings: " . json_encode($query->getBindings()) . "\n";

$products = $query->get();

echo "Products found: " . $products->count() . "\n";

if ($products->count() > 0) {
    echo "First product details:\n";
    echo json_encode($products->first()->toArray(), JSON_PRETTY_PRINT) . "\n";
} else {
    echo "No products found. Let's check data separately:\n";
    echo "Products count: " . Product::count() . "\n";
    echo "Product batches count: " . ProductBatch::count() . "\n";
    echo "Product stocks count: " . ProductStock::count() . "\n";

    echo "Active batches: " . ProductBatch::where('is_active', 1)->count() . "\n";
    echo "Stocks for store 1: " . ProductStock::where('store_id', 1)->count() . "\n";
}
