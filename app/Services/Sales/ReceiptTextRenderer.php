<?php

namespace App\Services\Sales;

/**
 * Plain text receipts for thermal printers, built from a sale, its lines and the shop settings.
 * productSided() is the one in use; productLine() is the older column layout.
 */
class ReceiptTextRenderer
{
    private function formatQuantity($quantity)
    {
        if (fmod($quantity, 1) !== 0.0) {
            return number_format($quantity, 2);
        }
        return (int) $quantity;
    }

    public function productLine($sale, $user, $saleItems, $settingArray)
    {
        $lineWidth  = 44;
        $lineSep    = str_repeat("-", $lineWidth) . "\r\n";
        $itemSep    = str_repeat(".", $lineWidth) . "\r\n";

        // columns (sum = 42)
        $colQty     = 6;   // "999x"
        $colUnit    = 10;  // unit price
        $colDisc    = 10;  // discount
        $colTotal   = 18;  // line total

        $summaryPad = 24;

        $text  = $lineSep;
        $text .= "Sale: " . $sale->sale_prefix . "/" . $sale->invoice_number . "\r\n";
        $text .= "Date: " . date('d-m-Y h:i A', strtotime($sale->created_at)) . "\r\n";
        $text .= "Client: " . $sale->client_name . "\r\n";
        $text .= "Created By: " . ($user ? $user->name : '-') . "\r\n";
        $text .= $lineSep;

        // items
        foreach ($saleItems as $index => $item) {
            $productName = $item->product_name;
            if ($item->account_number) {
                $productName .= " (Acc:" . $item->account_number . ")";
            }

            // Line 1 → product name
            $text .= '#' . str_pad(($index + 1) . '.', 2, '0', STR_PAD_LEFT) . $productName . "\r\n";

            // calculate values
            $qty   = $item->quantity;
            $unit  = number_format($item->unit_price, 2);
            $disc  = number_format($item->discount, 2);
            $total = number_format(($item->unit_price * $item->quantity) - $item->discount, 2);

            // Line 2 → aligned columns
            $lineQty   = str_pad($qty, $colQty, " ", STR_PAD_BOTH);
            $lineUnit  = str_pad($unit, $colUnit, " ", STR_PAD_LEFT);
            $lineDisc  = str_pad($disc, $colDisc, " ", STR_PAD_LEFT);
            if ($total == 0) {
                $lineTotal = str_pad('FREE', $colTotal, " ", STR_PAD_LEFT);
            } else {
                $lineTotal = str_pad($total, $colTotal, " ", STR_PAD_LEFT);
            }

            $text .= $lineQty . 'x' . $lineUnit . $lineDisc . $lineTotal . "\r\n";
            if ($index < count($saleItems) - 1) {
                $text .= $itemSep;
            }
        }

        $text .= $lineSep;
        $text .= str_pad("Total:", $summaryPad, " ", STR_PAD_RIGHT) . str_pad(number_format($sale->total_amount + $sale->discount, 2), 20, " ", STR_PAD_LEFT) . "\r\n";
        $text .= str_pad("Discount:", $summaryPad, " ", STR_PAD_RIGHT) . str_pad(number_format($sale->discount, 2), 20, " ", STR_PAD_LEFT) . "\r\n";
        $text .= str_pad("Net Total:", $summaryPad, " ", STR_PAD_RIGHT) . str_pad(number_format($sale->total_amount, 2), 20, " ", STR_PAD_LEFT) . "\r\n";
        $text .= str_pad("Paid:", $summaryPad, " ", STR_PAD_RIGHT) . str_pad(number_format($sale->amount_received, 2), 20, " ", STR_PAD_LEFT) . "\r\n";
        $text .= str_pad("Change:", $summaryPad, " ", STR_PAD_RIGHT) . str_pad(number_format($sale->amount_received - $sale->total_amount, 2), 20, " ", STR_PAD_LEFT) . "\r\n\n";
        $text .= $settingArray['sale_receipt_note'] . "\r\n\n";

        return $text;
    }
    public function productSided($sale, $user, $saleItems, $settingArray)
    {
        $lineWidth = 48;
        $lineSep = str_repeat("-", $lineWidth) . "\r\n";
        $itemSep = str_repeat(".", $lineWidth) . "\r\n";

        // Define fixed column widths
        $colQty     = 10;   // "999x"
        $colUnit    = 12;  // unit price
        $colTotal   = 18;  // line total

        $summaryPad = 24;
        $text = $lineSep;
        $text .= "Sale: " . $sale->sale_prefix . "/" . $sale->invoice_number . "\r\n";
        $text .= "Date: " . date('d-m-Y h:i A', strtotime($sale->created_at)) . "\r\n";
        $text .= "Client: " . $sale->client_name . "\r\n";
        $text .= "Created By: " . ($user ? $user->name : '-') . "\r\n";
        $text .= $lineSep;

        foreach ($saleItems as $index => $item) {
            $productName = $item->product_name;
            if ($item->account_number) {
                $productName .= " (Acc:" . $item->account_number . ")";
            }

            $unit  = number_format($item->unit_price, 2);
            $disc = ($item->discount*$item->quantity)+$item->flat_discount;
            $total = number_format(($item->unit_price * $item->quantity) - $disc, 2);

            // Calculate space for product name (everything before the 'Total' column)
            $spaceBeforeTotal = $lineWidth - $colTotal;
            $prefix = '#' . str_pad(($index + 1) . '.', 2, '0', STR_PAD_LEFT) . ' ';

            // Wrap product name to fit available width
            $lines = [];
            $words = explode(' ', $productName);
            $currentLine = $prefix;
            foreach ($words as $word) {
                if (strlen($currentLine . $word) + 1 > $spaceBeforeTotal) {
                    $lines[] = $currentLine;
                    $currentLine = '   ' . $word; // indent wrapped lines
                } else {
                    $currentLine .= ($currentLine === $prefix ? '' : ' ') . $word;
                }
            }
            $lines[] = $currentLine;

            // Add wrapped product name lines
            foreach ($lines as $line) {
                $text .= $line . "\r\n";
            }

            // Line 2 → aligned columns
            $lineQty   = str_pad('   ' . $item->quantity . ($item->free_quantity !=0 ? '+[Free: ' . $this->formatQuantity($item->free_quantity) . ']' : ''), $colQty, " ", STR_PAD_BOTH);
            $lineUnit  = str_pad($unit, $colUnit, " ", STR_PAD_LEFT);
            $lineTotal = ($disc == 0) ? str_pad($total, $colTotal, " ", STR_PAD_LEFT) : str_pad('(' . ($disc)*-1 . ') ' . $total, $colTotal, " ", STR_PAD_LEFT);

            $text .= $lineUnit .$lineQty. $lineTotal . "\r\n";

            if ($index < count($saleItems) - 1) {
                $text .= $itemSep;
            }
        }

        $text .= $lineSep;
        $text .= str_pad("Total:", $summaryPad, " ", STR_PAD_RIGHT) . str_pad(number_format($sale->total_amount + $sale->discount, 2), 24, " ", STR_PAD_LEFT) . "\r\n";
        $text .= str_pad("Discount:", $summaryPad, " ", STR_PAD_RIGHT) . str_pad(number_format($sale->discount, 2), 24, " ", STR_PAD_LEFT) . "\r\n";
        $text .= str_pad("Net Total:", $summaryPad, " ", STR_PAD_RIGHT) . str_pad(number_format($sale->total_amount, 2), 24, " ", STR_PAD_LEFT) . "\r\n";
        $text .= str_pad("Paid:", $summaryPad, " ", STR_PAD_RIGHT) . str_pad(number_format($sale->amount_received, 2), 24, " ", STR_PAD_LEFT) . "\r\n";
        $text .= str_pad("Change:", $summaryPad, " ", STR_PAD_RIGHT) . str_pad(number_format($sale->amount_received - $sale->total_amount, 2), 24, " ", STR_PAD_LEFT) . "\r\n\n";
        $text .= $settingArray['sale_receipt_note'] . "\r\n\n";

        return $text;
    }
}
