<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Transaction;
use App\Models\PurchaseTransaction;
use App\Models\Purchase;
use App\Models\Contact;
use App\Models\Sale;
use App\Models\CashLog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class TransactionController extends Controller
{
    public function storeCustomerTransaction(Request $request)
    {
        $amount = $request->input('amount');
        $paymentMethod = $request->input('payment_method');
        $transactionDate = $request->input('transaction_date');
        $note = $request->input('note');
        $contactId = $request->input('contact_id');
        $saleID = $request->input('transaction_id');
        $storeId = $request->input('store_id');

        $transactionData = [
            'sales_id' => $saleID,
            'store_id' => $storeId,
            'contact_id' => $contactId,
            'transaction_date' => $transactionDate,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'note' => $note,
        ];

        DB::beginTransaction();
        try {
            $contact = Contact::findOrFail($contactId);
            $user = Auth::user();

            $transactionData['transaction_type'] = 'account';
            if ($paymentMethod == 'Account' && $request->has('transaction_id')) {
                $transactionData['transaction_type'] = 'sale';
            } elseif ($paymentMethod != 'Account' && $request->has('transaction_id')) {
                $transactionData['transaction_type'] = 'sale';
                $contact->incrementBalance($amount, $user);
            } else {
                $contact->incrementBalance($amount, $user);
            }

            $parentTransaction = Transaction::create($transactionData);

            //If it has transaction id, it means, it comes from sale, if not then it's from account (from contact view)
            if ($request->has('transaction_id')) {
                $sale = Sale::where('id', $saleID)->first();
                if ($sale) {
                    // Increment the amount_received field by the given amount
                    $sale->increment('amount_received', $amount);

                    // Check if the total amount received is greater than or equal to the total amount
                    if ($sale->amount_received >= $sale->total_amount) {
                        $sale->status = 'completed';
                        $sale->payment_status = 'completed';
                    }

                    // Save the changes to the Sale record
                    $sale->save();
                }
            } else {
                $pendingSales = Sale::where('contact_id', $contactId)
                    ->whereRaw('total_amount > amount_received')
                    ->orderBy('sale_date', 'asc') // Oldest sales first
                    ->get();

                // Calculate the total pending amount for all sales
                $pendingSalesBalance = $pendingSales->sum(function ($sale) {
                    return $sale->total_amount - $sale->amount_received;
                });

                $contactBalance = $contact->balance - $amount;
                $pendingSalesBalance = -$pendingSalesBalance;

                $openingBalance = $contactBalance - $pendingSalesBalance;
                $remainingAmount = $amount + $openingBalance;

                // dd($remainingBalance);
                foreach ($pendingSales as $sale) {
                    if ($remainingAmount <= 0) {
                        break; // Stop allocation when amount is exhausted
                    }

                    $salePendingAmount = $sale->total_amount - $sale->amount_received;

                    // Allocate to this sale
                    $allocationAmount = min($remainingAmount, $salePendingAmount);
                    $sale->increment('amount_received', $allocationAmount);

                    // Update sale status if fully paid
                    if ($sale->amount_received >= $sale->total_amount) {
                        $sale->status = 'completed';
                        $sale->payment_status = 'completed';
                    } else {
                        $sale->payment_status = 'pending';
                    }

                    $sale->save();

                    // Log the transaction for this sale
                    Transaction::create([
                        'sales_id' => $sale->id,
                        'store_id' => $storeId,
                        'contact_id' => $contactId,
                        'transaction_date' => $transactionDate,
                        'amount' => $allocationAmount,
                        'payment_method' => 'Account',
                        'note' => $note,
                        'transaction_type' => 'sale',
                        'parent_id' => $parentTransaction->id,
                    ]);

                    // Reduce remaining amount
                    $remainingAmount -= $allocationAmount;
                } //End of foreach loop | foreach ($pendingSales as $sale)
            }


            DB::commit();
            return response()->json([
                'message' => "Payment added successfully",
            ], 200);
        } catch (\Exception $e) {
            // Rollback transaction in case of error
            DB::rollBack();

            Log::error('Transaction failed', [
                'error_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return error response
            return response()->json(['error' => $this->errorMessage($e)], 500);
        }
    }

    public function storeVendorTransaction(Request $request)
    {
        $amount = $request->input('amount');
        $paymentMethod = $request->input('payment_method');
        $transactionDate = $request->input('transaction_date');
        $note = $request->input('note');
        $contactId = $request->input('contact_id');
        $purchaseID = $request->input('transaction_id');
        $storeId = $request->input('store_id');

        $transactionData = [
            'purchase_id' => $purchaseID,
            'store_id' => $storeId,
            'contact_id' => $contactId,
            'transaction_date' => $transactionDate,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'note' => $note,
        ];

        DB::beginTransaction();
        try {
            $contact = Contact::findOrFail($contactId);
            $user = Auth::user();

            $transactionData['transaction_type'] = 'account';
            if ($paymentMethod == 'Account' && $request->has('transaction_id')) {
                $transactionData['transaction_type'] = 'purchase';
            } elseif ($paymentMethod != 'Account' && $request->has('transaction_id')) {
                $transactionData['transaction_type'] = 'purchase';
                $contact->incrementBalance($amount, $user);
            } else {
                $contact->incrementBalance($amount, $user);
            }

            $parentTransaction = PurchaseTransaction::create($transactionData);

            if ($request->has('transaction_id')) {
                $purchase = Purchase::where('id', $purchaseID)->first();
                if ($purchase) {
                    // Increment the amount_paid field by the given amount
                    $purchase->increment('amount_paid', $amount);

                    // Check if the total amount received is greater than or equal to the total amount
                    if ($purchase->amount_paid >= $purchase->total_amount) $purchase->status = 'completed';

                    // Save the changes to the Purchase record
                    $purchase->save();
                }
            } else {
                $pendingPurchases = Purchase::where('contact_id', $contactId)
                    ->whereRaw('total_amount > amount_paid')
                    ->orderBy('purchase_date', 'asc') // Oldest purchase first
                    ->get();

                // Calculate the total pending amount for all purchases
                $pendingPurchaseBalance = $pendingPurchases->sum(function ($purchase) {
                    return $purchase->total_amount - $purchase->amount_paid;
                });

                $contactBalance = $contact->balance - $amount; //We deduct amount becuase it's already updated on Parent transaction
                $pendingPurchasesBalance = -$pendingPurchaseBalance; //We convert purchase balance to negative to calculate the pending balance

                $openingBalance = $contactBalance - $pendingPurchasesBalance;
                $remainingAmount = $amount + $openingBalance;

                // dd($openingBalance);
                foreach ($pendingPurchases as $purchase) {
                    if ($remainingAmount <= 0) {
                        break; // Stop allocation when amount is exhausted
                    }

                    $purchasePendingAmount = $purchase->total_amount - $purchase->amount_paid;

                    // Allocate to this purchase
                    $allocationAmount = min($remainingAmount, $purchasePendingAmount);
                    $purchase->increment('amount_paid', $allocationAmount);

                    // Update purchase status if fully paid
                    if ($purchase->amount_paid >= $purchase->total_amount) {
                        $purchase->status = 'completed';
                        $purchase->payment_status = 'completed';
                    } else {
                        $purchase->payment_status = 'pending';
                    }

                    $purchase->save();

                    // Log the transaction for this purchase
                    PurchaseTransaction::create([
                        'purchase_id' => $purchase->id,
                        'store_id' => $storeId,
                        'contact_id' => $contactId,
                        'transaction_date' => $transactionDate,
                        'amount' => $allocationAmount,
                        'payment_method' => 'Account',
                        'note' => $note,
                        'transaction_type' => 'purchase',
                        'parent_id' => $parentTransaction->id,
                    ]);

                    // Reduce remaining amount
                    $remainingAmount -= $allocationAmount;
                } //End of foreach loop | foreach ($pendingPurchases as $purchase)
            }

            DB::commit();
            return response()->json([
                'message' => "Payment added successfully",
            ], 200);
        } catch (\Exception $e) {
            // Rollback transaction in case of error
            DB::rollBack();

            Log::error('Transaction failed', [
                'error_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return error response
            return response()->json(['error' => 'Trnsaction failed'], 500);
        }
    }

    public function getPayments($type, $filters)
    {
        $query = ($type === 'sales') ? Transaction::query() : PurchaseTransaction::query();

        $query->select(
            ($type === 'sales' ? 'transactions.id' : 'purchase_transactions.id'),
            ($type === 'sales' ? 'sales_id as reference_id' : 'purchase_id as reference_id'),
            'store_id',
            'contact_id',
            'contacts.name as contact_name',
            'transaction_date',
            'amount',
            'payment_method',
            'transaction_type',
            'parent_id',
            'note',
        )
            ->join('contacts', 'contact_id', '=', 'contacts.id')
            ->orderBy('transaction_date', 'desc');

        if (isset($filters['contact_id'])) {
            $query->where('contact_id', $filters['contact_id']);
        }

        if (isset($filters['payment_method']) && $filters['payment_method'] !== 'All') {
            $query->where('payment_method', $filters['payment_method']);
        }

        if (isset($filters['start_date']) && isset($filters['end_date'])) {
            $query->whereBetween('transaction_date', [$filters['start_date'], $filters['end_date']]);
        }
        $perPage = $filters['per_page'] ?? 100;
        $results = $query->paginate($perPage);
        $results->appends($filters);
        return $results;
    }

    public function viewPayments(Request $request, $type = 'sales')
    {
        $filters = $request->only(['contact_id', 'payment_method', 'start_date', 'end_date']);
        $transactions = $this->getPayments($type, $filters);

        if ($type === 'sales') $contacts = Contact::select('id', 'name', 'balance')->customers()->get();
        else $contacts = Contact::select('id', 'name', 'balance')->vendors()->get();

        $contact_id = null;
        if (isset($request->contact_id) && !empty($request->contact_id)) {
            $contact_id = $request->contact_id;
        }
        return Inertia::render('Payment/Payment', [
            'payments' => $transactions,
            'transactionType' => $type,
            'pageLabel' => 'Payments',
            'contacts' => $contacts,
            'selected_contact' => $contact_id,
        ]);
    }

    public function findPayments(Request $request, $type)
    {
        $transaction_id = $request->transaction_id;
        $query = ($type === 'sale') ? Transaction::query() : PurchaseTransaction::query();
        $query = $query->select('amount', 'payment_method', 'transaction_date');
        $query = ($type === 'sale') ? $query->where('sales_id', $transaction_id) : $query->where('purchase_id', $transaction_id);

        $results = $query->get();
        return response()->json(['payments' => $results,]);
    }

    public function deletePayment(Request $request, $type)
    {
        $user = Auth::user();
        if($user->user_role!='admin'){
            return response()->json(['error' => 'You are not authorized to delete payment'], 403);
        }

        $transaction_id = $request->transaction_id;
        $type = ($type === 'sales' || $type === 'sale') ? 'sales' : 'purchases';
        $query = ($type === 'sales') ? Transaction::query() : PurchaseTransaction::query();

        if ($query->where('id', $transaction_id)->value('parent_id')) {
            return response()->json(['error' => 'This payment have a parent transaction. Please delete the parent transaction first.'], 406);
        }

        $transaction = $query->where('id', $transaction_id)->first();

        DB::beginTransaction();
        try {
            if ($transaction->payment_method == 'Cash') {
                CashLog::where('source', $type)->where('reference_id', $transaction->id)->delete();
            }

            if ($transaction->payment_method !== 'Account') {
                $contact = Contact::findOrFail($transaction->contact_id);
                $contact->incrementBalance($transaction->amount*-1, $user);
            }

            // Handle the parent transaction
            $reference = ($type === 'sales') ? Sale::where('id', $transaction->sales_id)->first() : Purchase::where('id', $transaction->purchase_id)->first();
            if ($reference) {
                if ($type === 'sales') {
                    $reference->decrement('amount_received', $transaction->amount);
                } else {
                    $reference->decrement('amount_paid', $transaction->amount);
                }

                if ($reference->amount_received != $reference->total_amount) {
                    $reference->status = 'pending';
                    $reference->payment_status = 'pending';
                } else {
                    $reference->status = 'completed';
                    $reference->payment_status = 'completed';
                }
                $reference->save();
            }

            // Handle child transactions
            $child_transactions = ($type === 'sales') ? Transaction::where('parent_id', $transaction->id)->get() : PurchaseTransaction::where('parent_id', $transaction->id)->get();
            if ($child_transactions->count() > 0) {
                foreach ($child_transactions as $child) {
                    $reference = ($type === 'sales') ? Sale::where('id', $child->sales_id)->first() : Purchase::where('id', $child->purchase_id)->first();
                    if ($reference) {
                        if ($type === 'sales') {
                            $reference->decrement('amount_received', $child->amount);
                        } else {
                            $reference->decrement('amount_paid', $child->amount);
                        }

                        if ($reference->amount_received != $reference->total_amount) {
                            $reference->status = 'pending';
                            $reference->payment_status = 'pending';
                        } else {
                            $reference->status = 'completed';
                            $reference->payment_status = 'completed';
                        }
                        $reference->save();
                    }
                    $child->delete();
                }
            }

            $transaction->delete();

        } catch (\Exception $e) {
            // Rollback transaction in case of error
            DB::rollBack();

            Log::error('Delete transaction failed', [
                'error_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return error response
            return response()->json(['error' => $this->errorMessage($e)], 500);
        }

        DB::commit();

        return response()->json(['message' => 'Payment has been deleted']);
    }
}
