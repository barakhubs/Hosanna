<?php

namespace App\Http\Controllers;

use App\Models\ProductBatch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\QuantityAdjustment;
use App\Models\ProductStock;

use Illuminate\Support\Facades\DB;

class QuantityController extends Controller
{
    public function store(Request $request)
    {
        // Validate the incoming request data
        $validated = $request->validate([
            'batch_id' => 'required',
            'stock_id' => 'nullable|integer',  // Can be null to create a new ProductStock
            'quantity' => 'required',
            'reason' => 'required|string',
            'store_id' => 'required|integer',
        ]);

        // Start database transaction to ensure atomicity
        DB::beginTransaction();

        try {
            // Attempt to find existing ProductStock by store_id and batch_id
            $productStock = ProductStock::where('store_id', $validated['store_id'])
                ->where('batch_id', $validated['batch_id'])
                ->first();

            if ($productStock) {
                // If stock exists, update the quantity
                $previousQuantity = $productStock->quantity;
                $productStock->quantity += $validated['quantity'];
                $productStock->save();
            } else {
                $product = ProductBatch::find($validated['batch_id']);
                // If stock doesn't exist, create a new ProductStock record
                $productStock = ProductStock::create([
                    'store_id' => $validated['store_id'],
                    'batch_id' => $validated['batch_id'],
                    'quantity' => $validated['quantity'],
                    'product_id' => $product->product_id
                ]);

                // Since this is a new record, previous quantity will be 0
                $previousQuantity = 0;
            }

            // Create a new QuantityAdjustment record
            $quantityAdjustment = new QuantityAdjustment([
                'batch_id' => $validated['batch_id'],
                'stock_id' => $productStock->id,
                'previous_quantity' => $previousQuantity,
                'adjusted_quantity' => $validated['quantity'],
                'reason' => $validated['reason'],
            ]);

            $quantityAdjustment->save();

            // Commit transaction
            DB::commit();

            // Return success response
            return response()->json(['message' => 'Quantity adjustment and stock update successful.'], 200);
        } catch (\Exception $e) {
            // Rollback transaction in case of error
            DB::rollBack();

            // Return error response
            return response()->json(['error' => $this->errorMessage($e)], 500);
        }
    }
    public function getAdjustmentsLog($stock_id)
    {
        $adjustments = QuantityAdjustment::where('stock_id', $stock_id)
            ->join('product_stocks', 'quantity_adjustments.stock_id', '=', 'product_stocks.id')
            ->join('products', 'product_stocks.product_id', '=', 'products.id')
            ->select('quantity_adjustments.*', 'products.name')
            ->orderBy('quantity_adjustments.created_at', 'desc')
            ->get();
        return Inertia::render('Product/QuantityAdjustmentsLog', [
            'adjustments' => $adjustments,
            'pageLabel' => 'Quantity Adjustments Log',
        ]);
    }
}
