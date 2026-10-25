<?php

namespace App\Services\Payments;

use App\Models\Contact;
use App\Models\Purchase;
use App\Models\PurchaseTransaction;
use App\Models\Sale;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;

/**
 * Records payments against sales and purchases. Both sides follow the same rules,
 * only the tables and column names differ, so each side is described in SIDES.
 */
class PaymentRecorder
{
    public const SALE = 'sale';
    public const PURCHASE = 'purchase';

    private const SIDES = [
        self::SALE => [
            'transaction' => Transaction::class,
            'order' => Sale::class,
            'order_key' => 'sales_id',
            'paid_column' => 'amount_received',
            'date_column' => 'sale_date',
            'type' => 'sale',
            // paying a single sale also marks its payment_status, a single purchase does not
            'direct_payment_sets_payment_status' => true,
        ],
        self::PURCHASE => [
            'transaction' => PurchaseTransaction::class,
            'order' => Purchase::class,
            'order_key' => 'purchase_id',
            'paid_column' => 'amount_paid',
            'date_column' => 'purchase_date',
            'type' => 'purchase',
            'direct_payment_sets_payment_status' => false,
        ],
    ];

    /**
     * Payments taken while saving a sale or purchase.
     * Account and Credit come off the contact balance; Credit is not counted as paid.
     * Returns the amount that counts as paid.
     *
     * @param  array  $payments  [['payment_method' => ..., 'amount' => ...], ...]
     */
    public function recordOrderPayments(string $side, Model $order, $payments, $transactionDate): float
    {
        $config = self::SIDES[$side];
        $paid = 0;

        foreach ($payments as $payment) {
            $transactionData = [
                $config['order_key'] => $order->id,
                'store_id' => $order->store_id,
                'contact_id' => $order->contact_id,
                'transaction_date' => $transactionDate,
                'amount' => $payment['amount'],
                'payment_method' => $payment['payment_method'],
            ];

            if ($payment['payment_method'] != 'Credit') {
                if ($payment['payment_method'] == 'Account') {
                    $transactionData['transaction_type'] = 'account';
                    Contact::where('id', $order->contact_id)->decrement('balance', $payment['amount']);
                } else {
                    $transactionData['transaction_type'] = $config['type'];
                }

                $paid += $payment['amount'];
                $config['transaction']::create($transactionData);
            } else {
                $transactionData['transaction_type'] = $config['type'];
                $config['transaction']::create($transactionData);
                Contact::where('id', $order->contact_id)->decrement('balance', $payment['amount']);
            }
        }

        return $paid;
    }
}
