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

    /**
     * A payment from a customer or to a vendor made outside checkout.
     * With an order it is applied to that order; without one it goes on the
     * contact's account and is spread over their unpaid orders, oldest first.
     *
     * @param  array  $data  order_id, store_id, contact_id, transaction_date, amount, payment_method, note
     */
    public function recordContactPayment(string $side, array $data, bool $forOrder, $user): void
    {
        $config = self::SIDES[$side];
        $amount = $data['amount'];
        $paymentMethod = $data['payment_method'];

        $contact = Contact::findOrFail($data['contact_id']);

        $transactionData = [
            $config['order_key'] => $data['order_id'],
            'store_id' => $data['store_id'],
            'contact_id' => $data['contact_id'],
            'transaction_date' => $data['transaction_date'],
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'note' => $data['note'],
            'transaction_type' => 'account',
        ];

        if ($paymentMethod == 'Account' && $forOrder) {
            $transactionData['transaction_type'] = $config['type'];
        } elseif ($paymentMethod != 'Account' && $forOrder) {
            $transactionData['transaction_type'] = $config['type'];
            $contact->incrementBalance($amount, $user);
        } else {
            $contact->incrementBalance($amount, $user);
        }

        $parentTransaction = $config['transaction']::create($transactionData);

        if ($forOrder) {
            $this->applyToOrder($config, $data['order_id'], $amount);
        } else {
            $this->allocateToUnpaidOrders($config, $contact, $data, $parentTransaction);
        }
    }

    private function applyToOrder(array $config, $orderId, $amount): void
    {
        $order = $config['order']::where('id', $orderId)->first();
        if (!$order) {
            return;
        }

        $order->increment($config['paid_column'], $amount);

        if ($order->{$config['paid_column']} >= $order->total_amount) {
            $order->status = 'completed';
            if ($config['direct_payment_sets_payment_status']) {
                $order->payment_status = 'completed';
            }
        }

        $order->save();
    }

    private function allocateToUnpaidOrders(array $config, Contact $contact, array $data, Model $parentTransaction): void
    {
        $paidColumn = $config['paid_column'];
        $amount = $data['amount'];

        $pendingOrders = $config['order']::where('contact_id', $data['contact_id'])
            ->whereRaw("total_amount > {$paidColumn}")
            ->orderBy($config['date_column'], 'asc')
            ->get();

        $pendingTotal = $pendingOrders->sum(fn ($order) => $order->total_amount - $order->{$paidColumn});

        // The balance already includes this payment, so take it back out to find
        // what was owed before it, apart from the unpaid orders themselves.
        $contactBalance = $contact->balance - $amount;
        $openingBalance = $contactBalance + $pendingTotal;
        $remainingAmount = $amount + $openingBalance;

        foreach ($pendingOrders as $order) {
            if ($remainingAmount <= 0) {
                break;
            }

            $allocationAmount = min($remainingAmount, $order->total_amount - $order->{$paidColumn});
            $order->increment($paidColumn, $allocationAmount);

            if ($order->{$paidColumn} >= $order->total_amount) {
                $order->status = 'completed';
                $order->payment_status = 'completed';
            } else {
                $order->payment_status = 'pending';
            }
            $order->save();

            $config['transaction']::create([
                $config['order_key'] => $order->id,
                'store_id' => $data['store_id'],
                'contact_id' => $data['contact_id'],
                'transaction_date' => $data['transaction_date'],
                'amount' => $allocationAmount,
                'payment_method' => 'Account',
                'note' => $data['note'],
                'transaction_type' => $config['type'],
                'parent_id' => $parentTransaction->id,
            ]);

            $remainingAmount -= $allocationAmount;
        }
    }
}
