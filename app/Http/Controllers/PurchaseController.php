<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Contact;
use App\Models\Store;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\ProductBatch;
use App\Services\Inventory\StockService;
use App\Models\PurchaseTransaction;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseController extends Controller
{
    public function __construct(private StockService $stock)
    {
    }

    public function getPurchases($filters)
    {
        $query = Purchase::query();
        $query->select(
            'purchases.id',
            'contact_id',            // Customer ID
            'purchase_date',              // Purchase date
            'total_amount',           // Total amount (Total amount after discount [net_total - discount])
            'amount_paid',
            'discount',                // Discount
            'store_id',
            'status',
            'contacts.name',
        )
            ->leftJoin('contacts', 'purchases.contact_id', '=', 'contacts.id')
            ->orderBy('purchase_date', 'desc');

        if (isset($filters['contact_id'])) {
            $query->where('contact_id', $filters['contact_id']);
        }

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['start_date']) && isset($filters['end_date'])) {
            $query->whereBetween('purchase_date', [$filters['start_date'], $filters['end_date']]);
        }
        $results = $query->paginate(25);
        $results->appends($filters);
        return $results;
    }

    public function index(Request $request)
    {
        $filters = $request->only(['contact_id', 'start_date', 'end_date', 'status']);
        $contacts = Contact::select('id', 'name', 'balance')->vendors()->get();
        $purchases = $this->getPurchases($filters);
        return Inertia::render('Purchase/Purchase', [
            'purchases' => $purchases,
            'pageLabel' => 'Purchases',
            'contacts' => $contacts,
        ]);
    }

    public function create()
    {
        $contacts = Contact::select('id', 'name', 'balance')->vendors()->get();
        $stores = Store::select('id', 'name')->get();
        return Inertia::render('Purchase/PurchaseForm/PurchaseForm', [
            'products' => [],
            'vendors' => $contacts,
            'stores' => $stores,
            'pageLabel' => 'New Purchase',
        ]);
    }

    public function store(Request $request)
    {
        //Validate the incoming request data
        $validatedData = $request->validate([
            'store_id' => 'required|integer',
            'contact_id' => 'required|integer',
            'purchase_date' => 'required|date',
            'net_total' => 'required|numeric',
            'discount' => 'nullable|numeric',
            'reference_no' => 'nullable|string',
            'note' => 'nullable|string',
        ]);
        // dd($request->cartItems);
        DB::beginTransaction();
        try {
            // Create a new purchase record
            $purchase = Purchase::create([
                'store_id' => $validatedData['store_id'] ?? 1,
                'contact_id' => $validatedData['contact_id'],
                'purchase_date' => $validatedData['purchase_date'],
                'total_amount' => $validatedData['net_total'], //Total after discount
                'discount' => $validatedData['discount'] ?? 0, // Optional, defaults to 0
                'amount_paid' => $request->amount_paid ?? 0,
                'profit_amount' => $request->profit_amount ?? 0,
                'payment_status' => $validatedData['payment_status'] ?? 'pending',
                'status' => $validatedData['status'] ?? 'pending',
                'reference_no' => $validatedData['reference_no'] ?? null, // Optional
                'note' => $validatedData['note'] ?? null, // Optional
            ]);

            $cartItems = $request->cartItems;
            $payments = $request->payments;
            foreach ($cartItems as $item) {
                $description = null;
                $item_id = $item['id'];
                $batch_id = $item['batch_id'];

                if ($item['product_type'] == 'custom') {
                    $description = $item['name'];
                    $item_id = null;
                    $batch_id = null;
                }
                // Handle new or existing product batches
                else if ($item['status'] == 'new') {
                    // Create a new product batch
                    $productBatch = ProductBatch::create([
                        'product_id' => $item['id'],
                        'batch_number' => $item['batch_number'],
                        'cost' => $item['cost'],
                        'price' => $item['price'],
                        'expiry_date' => null,
                    ]);

                    // Use the newly created batch's ID
                    $batch_id = $productBatch->id;
                } else {
                    // Retrieve the existing product batch
                    $productBatch = ProductBatch::where('batch_number', $item['batch_number'])
                        ->where('product_id', $item['id'])
                        ->where('cost', $item['cost'])
                        ->first();

                    if ($productBatch) {
                        $batch_id = $productBatch->id;
                        $productBatch->price = $item['price'];
                        $productBatch->save();
                    } else {
                        throw new \Exception("Batch not found for item: {$item['name']}");
                    }
                }

                // Create a new purchase item
                PurchaseItem::create([
                    'purchase_id' => $purchase->id, // Associate the purchase item with the newly created purchase
                    'product_id' => $item_id, // Product ID (assuming you have this)
                    'batch_id' => $batch_id, // Batch ID from the cart item
                    'quantity' => $item['quantity'], // Quantity purchased
                    'unit_price' => $item['price'], // Purchase price per unit
                    'unit_cost' => $item['cost'], // Cost price per unit
                    'purchase_date' => $purchase['purchase_date'],
                    'description' => $description,
                ]);

                if ($item['product_type'] == 'simple') { //Custom product does not have stock

                    $this->stock->receive($purchase->store_id, $batch_id, $item['id'], $item['quantity']);
                }
            }

            $purchaseAmountPaid = 0;
            foreach ($payments as $payment) {
                $transactionData = [
                    'purchase_id' => $purchase->id,
                    'store_id' => $purchase->store_id,
                    'contact_id' => $purchase->contact_id,
                    'transaction_date' => now(),
                    'amount' => $payment['amount'], // Amount from the payment array
                    'payment_method' => $payment['payment_method'],
                ];

                if ($payment['payment_method'] != 'Credit') {
                    // Determine transaction type based on the payment method
                    if ($payment['payment_method'] == 'Account') {
                        // Set transaction type to 'account' for account payments
                        $transactionData['transaction_type'] = 'account';
                        Contact::where('id', $purchase->contact_id)->decrement('balance', $payment['amount']);
                    } else {
                        // Set transaction type to 'purchase' for other payment methods
                        $transactionData['transaction_type'] = 'purchase';
                    }

                    // Update the total amount received
                    $purchaseAmountPaid += $payment['amount'];

                    // Create the transaction
                    PurchaseTransaction::create($transactionData);
                } else if ($payment['payment_method'] == 'Credit') {
                    $transactionData['transaction_type'] = 'purchase';
                    PurchaseTransaction::create($transactionData);
                    Contact::where('id', $purchase->contact_id)->decrement('balance', $payment['amount']);
                }
            }
            if ($purchaseAmountPaid >= $purchase->total_amount) $purchase->status = 'completed';
            $purchase->amount_paid = $purchaseAmountPaid;
            $purchase->save(); //Update amount received

            DB::commit();
            // Return a successful response
            return response()->json([
                'message' => 'Purchase created successfully!',
                'purchase' => '$purchase',
                'cartItems' => $cartItems,
                'payments' => $payments,
            ], 201);
        } catch (\Exception $e) {
            // Rollback transaction in case of error
            DB::rollBack();

            $this->logError('Transaction failed', $e);

            // Return error response
            return response()->json(['error' => $this->errorMessage($e)], 500);
        }
    }
}
