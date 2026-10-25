<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\SaleItem;

/**
 * Adds charge lines (tax, delivery, ...) to a sale. A return instead gets
 * the original sale's charges reversed in proportion to the quantity returned.
 */
class ChargeCalculator
{
    /**
     * @param  array  $charges  charges picked at the till (ignored for returns)
     * @param  float  $productLineTotal  sum of the product lines after line discounts
     * @param  float  $returnedQuantity  sum of the line quantities (negative on returns)
     */
    public function apply(Sale $sale, $charges, $productLineTotal, $returnedQuantity): void
    {
        // Calculate base amount for charges (product subtotal - discount)
        $chargeBaseAmount = $productLineTotal - $sale->discount;

        if ($sale->sale_type === 'return' && $sale->reference_id) {
            $totalChargeAmount = $this->reverseOriginalCharges($sale, $chargeBaseAmount, $returnedQuantity);
        } else {
            $totalChargeAmount = $this->addCharges($sale, $charges, $chargeBaseAmount);
        }

        // Update sale with charge amounts (can be negative for returns)
        if ($totalChargeAmount != 0) {
            $sale->total_charge_amount = $totalChargeAmount;
            $sale->save();
        }
    }

    private function addCharges(Sale $sale, $charges, $chargeBaseAmount)
    {
        $totalChargeAmount = 0;

        if (empty($charges)) {
            return $totalChargeAmount;
        }

        foreach ($charges as $charge) {
            if ($charge['rate_type'] === 'percentage') {
                $chargeAmount = ($chargeBaseAmount * $charge['rate_value']) / 100;
            } else {
                $chargeAmount = $charge['rate_value'];
            }

            $totalChargeAmount += $chargeAmount;

            SaleItem::create([
                'sale_id' => $sale->id,
                'item_type' => 'charge',
                'charge_id' => $charge['id'],
                'charge_type' => $charge['charge_type'],
                'rate_value' => $charge['rate_value'],
                'rate_type' => $charge['rate_type'],
                'base_amount' => $chargeBaseAmount,
                'quantity' => 1,
                'unit_price' => $chargeAmount,
                'unit_cost' => 0,
                'sale_date' => $sale->sale_date,
                'description' => $charge['name'],
            ]);
        }

        return $totalChargeAmount;
    }

    private function reverseOriginalCharges(Sale $sale, $chargeBaseAmount, $returnedQuantity)
    {
        $totalChargeAmount = 0;

        $originalSale = Sale::find($sale->reference_id);
        if (!$originalSale) {
            return $totalChargeAmount;
        }

        $originalQuantity = SaleItem::where('sale_id', $originalSale->id)
            ->where(function ($query) {
                $query->where('item_type', '!=', 'charge')
                    ->orWhereNull('item_type');
            })
            ->get()
            ->sum('quantity');

        $returnProportionPercentage = 0;
        if ($originalQuantity > 0) {
            $returnProportionPercentage = ($returnedQuantity / $originalQuantity) * 100;
        }

        $originalCharges = SaleItem::where('sale_id', $originalSale->id)
            ->where('item_type', 'charge')
            ->get();

        foreach ($originalCharges as $originalCharge) {
            $reversalAmount = ($originalCharge->unit_price * $returnProportionPercentage) / 100;

            // Store as negative charge (reversal)
            $totalChargeAmount -= $reversalAmount;

            SaleItem::create([
                'sale_id' => $sale->id,
                'item_type' => 'charge',
                'charge_id' => $originalCharge->charge_id,
                'charge_type' => $originalCharge->charge_type,
                'rate_value' => $originalCharge->rate_value,
                'rate_type' => $originalCharge->rate_type,
                'base_amount' => $chargeBaseAmount,
                'quantity' => 1,
                'unit_price' => -$reversalAmount,
                'unit_cost' => 0,
                'sale_date' => $sale->sale_date,
                'description' => $originalCharge->description . ' (Reversal)',
                'notes' => 'Proportional reversal: ' . round($returnProportionPercentage, 2) . '% of original',
            ]);
        }

        return $totalChargeAmount;
    }
}
