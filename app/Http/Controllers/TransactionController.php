<?php

namespace App\Http\Controllers;

use App\Services\Payments\PaymentRecorder;
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
    public function __construct(private PaymentRecorder $payments)
    {
    }

    public function storeCustomerTransaction(Request $request)
    {
        $amount = $request->input('amount');
        $paymentMethod = $request->input('payment_method');
        $transactionDate = $request->input('transaction_date');
        $note = $request->input('note');
        $contactId = $request->input('contact_id');
        $saleID = $request->input('transaction_id');
        $storeId = $request->input('store_id');

        DB::beginTransaction();
        try {
            $this->payments->recordContactPayment(PaymentRecorder::SALE, [
                'order_id' => $saleID,
                'store_id' => $storeId,
                'contact_id' => $contactId,
                'transaction_date' => $transactionDate,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'note' => $note,
            ], $request->has('transaction_id'), Auth::user());

            DB::commit();
            return response()->json([
                'message' => "Payment added successfully",
            ], 200);
        } catch (\Exception $e) {
            // Rollback transaction in case of error
            DB::rollBack();

            $this->logError('Transaction failed', $e);

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

        DB::beginTransaction();
        try {
            $this->payments->recordContactPayment(PaymentRecorder::PURCHASE, [
                'order_id' => $purchaseID,
                'store_id' => $storeId,
                'contact_id' => $contactId,
                'transaction_date' => $transactionDate,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'note' => $note,
            ], $request->has('transaction_id'), Auth::user());

            DB::commit();
            return response()->json([
                'message' => "Payment added successfully",
            ], 200);
        } catch (\Exception $e) {
            // Rollback transaction in case of error
            DB::rollBack();

            $this->logError('Transaction failed', $e);

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
