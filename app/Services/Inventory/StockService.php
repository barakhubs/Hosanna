<?php

namespace App\Services\Inventory;

use App\Models\ProductStock;

/**
 * All stock quantity changes for sales and purchases go through here.
 * Rows are locked before they are changed so two tills can't both read the
 * same quantity and overwrite each other. Call these inside a DB transaction.
 */
class StockService
{
    /**
     * Take stock out for a sale. Never goes below zero.
     * Returns false when the store has no stock row for the batch.
     */
    public function deduct(int $storeId, ?int $batchId, float $quantity): bool
    {
        $stock = $this->lockedRow($storeId, $batchId);
        if (!$stock) {
            return false;
        }

        $stock->quantity -= $quantity;
        if ($stock->quantity < 0) {
            $stock->quantity = 0;
        }
        $stock->save();

        return true;
    }

    /**
     * Put stock back, e.g. when a sale is edited. Does nothing if the row is gone.
     */
    public function restore(int $storeId, ?int $batchId, float $quantity): void
    {
        $stock = $this->lockedRow($storeId, $batchId);
        if ($stock) {
            $stock->quantity += $quantity;
            $stock->save();
        }
    }

    /**
     * Add received stock, creating the row the first time a batch reaches a store.
     */
    public function receive(int $storeId, int $batchId, int $productId, float $quantity): ProductStock
    {
        $stock = ProductStock::where('store_id', $storeId)
            ->where('batch_id', $batchId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        if (!$stock) {
            $stock = ProductStock::create([
                'store_id' => $storeId,
                'batch_id' => $batchId,
                'product_id' => $productId,
                'quantity' => 0,
            ]);
        }

        $stock->quantity += $quantity;
        $stock->save();

        return $stock;
    }

    private function lockedRow(int $storeId, ?int $batchId): ?ProductStock
    {
        return ProductStock::where('store_id', $storeId)
            ->where('batch_id', $batchId)
            ->lockForUpdate()
            ->first();
    }
}
