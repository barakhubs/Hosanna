<?php

namespace App\Services\Sales;

use App\Models\ReloadAndBillMeta;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Transaction;
use App\Services\Inventory\StockService;
use App\Services\Payments\PaymentRecorder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Saves a sale from the till (new, edited or a return) with its payments,
 * items, stock movement and charges, all in one database transaction.
 */
class CheckoutService
{
    public function __construct(
        private StockService $stock,
        private PaymentRecorder $payments,
        private ChargeCalculator $charges,
        private SaleReversalService $reversal,
    ) {
    }

    /**
     * @param  array  $input  the checkout payload sent by the till
     *
     * @throws CheckoutException
     */
    public function checkout(array $input, $storeId, $userId): Sale
    {
        return DB::transaction(function () use ($input, $storeId, $userId) {
            $sale = $this->saveSale($input, $storeId, $userId);

            $this->recordPayments($sale, $input);

            [$productLineTotal, $returnedQuantity] = $this->recordItems($sale, $input['cartItems']);

            $this->charges->apply($sale, Arr::get($input, 'charges', []), $productLineTotal, $returnedQuantity);

            return $sale;
        });
    }

    /**
     * Creates the sale. For an edit, the old items and payments are undone first
     * (stock and balances put back) so they can be recorded again.
     */
    private function saveSale(array $input, $storeId, $userId): Sale
    {
        $saleData = [
            'store_id' => $storeId,
            'reference_id' => Arr::get($input, 'return_sale_id'),
            'sale_type' => Arr::get($input, 'return_sale') ? 'return' : 'sale',
            'contact_id' => Arr::get($input, 'contact_id'),
            'sale_date' => Arr::get($input, 'sale_date', Carbon::now()->toDateString()),
            'total_amount' => Arr::get($input, 'net_total'),
            'discount' => Arr::get($input, 'discount'),
            'amount_received' => Arr::get($input, 'amount_received', 0),
            'profit_amount' => Arr::get($input, 'profit_amount', 0),
            'status' => 'pending',
            'payment_status' => 'pending',
            'note' => Arr::get($input, 'note'),
            'created_by' => $userId,
            'cart_snapshot' => json_encode(Arr::get($input, 'cartItems')),
        ];

        $editSaleId = Arr::get($input, 'edit_sale_id');
        if (!Arr::get($input, 'edit_sale') || !$editSaleId) {
            return Sale::create($saleData);
        }

        $sale = Sale::findOrFail($editSaleId);
        $sale->update($saleData);
        $this->reversal->resetForEdit($sale);

        return $sale;
    }

    /**
     * A cash sale is paid in full in one go. Otherwise each payment is recorded
     * and the sale is completed once enough has been received.
     */
    private function recordPayments(Sale $sale, array $input): void
    {
        $total = Arr::get($input, 'net_total');

        if (Arr::get($input, 'payment_method', 'none') == 'Cash') {
            Transaction::create([
                'sales_id' => $sale->id,
                'store_id' => $sale->store_id,
                'contact_id' => $sale->contact_id,
                'transaction_date' => $sale->sale_date,
                'amount' => $total,
                'payment_method' => 'Cash',
                'transaction_type' => 'sale',
            ]);
            $sale->status = 'completed';
            $sale->payment_status = 'completed';
            $sale->save();

            return;
        }

        $amountReceived = Arr::get($input, 'amount_received', 0);
        $amountReceived += $this->payments->recordOrderPayments(PaymentRecorder::SALE, $sale, Arr::get($input, 'payments'), $sale->sale_date);

        if ($amountReceived >= $total) {
            $sale->payment_status = 'completed';
            $sale->status = 'completed';
        }

        $sale->amount_received = $amountReceived;
        $sale->save();
    }

    /**
     * @return array [product line total, total quantity], used for the charges
     */
    private function recordItems(Sale $sale, $cartItems): array
    {
        $productLineTotal = 0;
        $returnedQuantity = 0;

        foreach ($cartItems as $item) {
            $lineTotal = ($item['quantity'] * ($item['price'] - $item['discount'])) - ($item['flat_discount'] ?? 0);
            $productLineTotal += $lineTotal;
            $returnedQuantity += $item['quantity'];

            $saleItem = SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $item['id'],
                'batch_id' => $item['batch_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['price'],
                'unit_cost' => $item['cost'],
                'discount' => $item['discount'] ?? 0,
                'flat_discount' => $item['flat_discount'] ?? 0,
                'sale_date' => $sale->sale_date,
                'description' => $item['category_name'] ?? null,
                'is_free' => $item['is_free'] ?? 0,
                'free_quantity' => $item['free_quantity'] ?? 0,
            ]);

            if ($item['is_stock_managed'] == 1) {
                // Total deduction = sold qty + free qty
                $deduction = $item['quantity'] + ($item['free_quantity'] ?? 0);

                if (!$this->stock->deduct($sale->store_id, $item['batch_id'], $deduction)) {
                    throw CheckoutException::stockMissing();
                }
            }

            if ($item['product_type'] == 'reload') {
                $this->recordReload($saleItem, $item);
            }
        }

        return [$productLineTotal, $returnedQuantity];
    }

    private function recordReload(SaleItem $saleItem, array $item): void
    {
        $validator = Validator::make($item, ['account_number' => 'required']);
        if ($validator->fails()) {
            throw CheckoutException::reloadAccountMissing($validator->errors());
        }

        ReloadAndBillMeta::create([
            'sale_item_id' => $saleItem->id,
            'transaction_type' => 'reload',
            'account_number' => $item['account_number'],
            'commission' => $item['commission'],
            'additional_commission' => $item['additional_commission'],
            'description' => $item['product_type'],
        ]);
    }
}
