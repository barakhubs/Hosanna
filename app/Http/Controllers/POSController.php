<?php

namespace App\Http\Controllers;

use App\Settings\SettingsRepository;
use App\Queries\PosProductQuery;
use App\Support\SqlDialect;
use App\Support\StorageUrl;
use App\Models\CashLog;
use App\Models\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Transaction;
use App\Models\Contact;
use App\Services\Inventory\StockService;
use App\Services\Payments\PaymentRecorder;
use App\Services\Sales\ChargeCalculator;
use App\Models\Product;
use App\Models\Store;
use App\Models\ReloadAndBillMeta;
use App\Notifications\SaleCreated;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use App\Models\Charge;

class POSController extends Controller
{
    public function __construct(private StockService $stock, private PaymentRecorder $payments)
    {
    }

    public function getProducts($filters = [])
    {
        // Get current store ID with fallback
        $currentStoreId = session('store_id');
        if (!$currentStoreId && Auth::check()) {
            $currentStoreId = Auth::user()->store_id;
        }
        if (!$currentStoreId) {
            $currentStoreId = 1; // Fallback to store ID 1
        }

        return app(PosProductQuery::class)->get($currentStoreId, $filters);
    }

    public function getProductsByFilter(Request $request)
    {
        $categoryId = $request->input('category_id');
        $allProducts = $request->input('all_products');

        $filters = [];

        if ($categoryId != 0) {
            $filters['category_id'] = $categoryId;
        }

        //Return all products
        if ($allProducts == 'true') {
            $filters['all_products'] = true;
        }

        $products = $this->getProducts($filters);

        return response()->json($products);
    }

    public function index()
    {
        $contacts = Contact::select('id', 'name', 'balance')->customers()->get();
        $currentStore = Store::find(session('store_id', Auth::user()->store_id));

        if (!$currentStore) {
            return redirect()->route('store'); // Adjust the route name as necessary
        }
        $categories = Collection::where('collection_type', 'category')->get();
        $products = $this->getProducts();
        $miscSettings = app(SettingsRepository::class)->misc();
        $cart_first_focus = $miscSettings['cart_first_focus'] ?? 'quantity';

        // Get default charges
        $defaultCharges = Charge::where('is_active', true)
            ->where('is_default', true)
            ->get()
            ->map(function ($charge) {
                return [
                    'id' => $charge->id,
                    'name' => $charge->name,
                    'charge_type' => $charge->charge_type,
                    'rate_value' => $charge->rate_value,
                    'rate_type' => $charge->rate_type,
                    'is_active' => $charge->is_active,
                ];
            })
            ->toArray();

        return Inertia::render('POS/POS', [
            'products' => $products,
            'urlImage' => url('/storage/'),
            'customers' => $contacts,
            'currentStore' => $currentStore->name,
            'return_sale' => false,
            'sale_id' => null,
            'categories' => $categories,
            'cart_first_focus' => $cart_first_focus,
            'misc_settings' => $miscSettings,
            'default_charges' => $defaultCharges
        ]);
    }

    public function editSale(Request $request, $sale_id)
    {
        $sale = Sale::findOrFail($sale_id);
        $contacts = Contact::select('id', 'name', 'balance')->where('id', $sale->contact_id)->get();
        $currentStore = Store::find($sale->store_id);

        $categories = Collection::where('collection_type', 'category')->get();
        $products = $this->getProducts();
        $miscSettings = app(SettingsRepository::class)->misc();
        $cart_first_focus = $miscSettings['cart_first_focus'] ?? 'quantity';

        // Get charges from the sale's sale_items
        $defaultCharges = SaleItem::where('sale_id', $sale_id)
            ->where('item_type', 'charge')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->charge_id ?? $item->id ?? rand(100000, 999999),
                    'name' => $item->description ?? 'Charge',
                    'charge_type' => $item->charge_type ?? 'custom',
                    'rate_value' => $item->rate_value ?? 0,
                    'rate_type' => $item->rate_type ?? 'fixed',
                    'is_active' => true,
                ];
            })
            ->values()
            ->toArray();

        return Inertia::render('POS/POS', [
            'products' => $products,
            'urlImage' => url('/storage/'),
            'customers' => $contacts,
            'currentStore' => $currentStore->name,
            'return_sale' => false,
            'sale_id' => $sale->id,
            'categories' => $categories,
            'cart_first_focus' => $cart_first_focus,
            'edit_sale' => true,
            'sale_data' => $sale,
            'misc_settings' => $miscSettings,
            'default_charges' => $defaultCharges
        ]);
    }

    public function returnIndex(Request $request, $sale_id)
    {
        $imageUrl = StorageUrl::prefix('/storage/');

        $sale = Sale::find($sale_id);
        $contacts = Contact::select('id', 'name', 'balance')->where('id', $sale->contact_id)->get();
        $currentStore = Store::find($sale->store_id);

        $miscSettings = app(SettingsRepository::class)->misc();
        $cart_first_focus = $miscSettings['cart_first_focus'] ?? 'quantity';

        if (!$currentStore) {
            return redirect()->route('store'); // Adjust the route name as necessary
        }

        $products = Product::select(
            'products.id',
            DB::raw(SqlDialect::concat("'{$imageUrl}'", 'products.image_url') . " AS image_url"),
            'products.name',
            'si.discount',
            'products.is_stock_managed',
            DB::raw("COALESCE(pb.batch_number, 'N/A') AS batch_number"),
            'si.unit_cost as cost',
            'si.unit_price as price',
            'si.quantity',
            'products.meta_data',
            'products.product_type',
            'si.batch_id'
        )
            ->join('sale_items AS si', function ($join) use ($sale_id) {
                $join->on('products.id', '=', 'si.product_id')
                    ->where('si.sale_id', '=', $sale_id) // Ensure product is associated with the given sale_id
                    ->where(function ($q) {
                        $q->where('si.item_type', '!=', 'charge')
                            ->orWhereNull('si.item_type');
                    });
            })
            ->leftJoin('product_batches AS pb', 'products.id', '=', 'pb.product_id') // Join with product_batches using product_id
            ->leftJoin('product_stocks AS ps', 'pb.id', '=', 'si.batch_id') // Join with product_stocks using batch_id

            ->groupBy(
                'products.id',
                'products.image_url',
                'products.name',
                'si.discount',
                'products.is_stock_managed',
                DB::raw("COALESCE(pb.batch_number, 'N/A')"),
                'si.unit_cost',
                'si.unit_price',
                'si.batch_id',
                'si.quantity',
                'products.product_type',
                'products.meta_data'
            )
            ->get();

        // Get original charges from sale_items
        $defaultCharges = SaleItem::where('sale_id', $sale_id)
            ->where('item_type', 'charge')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->charge_id ?? $item->id ?? rand(100000, 999999),
                    'name' => $item->description ?? 'Charge',
                    'charge_type' => $item->charge_type ?? 'custom',
                    'rate_value' => $item->rate_value ?? 0,
                    'rate_type' => $item->rate_type ?? 'fixed',
                    'is_active' => true,
                ];
            })
            ->values()
            ->toArray();

        return Inertia::render('POS/POS', [
            'products' => $products,
            'urlImage' => url('/storage/'),
            'customers' => $contacts,
            'return_sale' => true,
            'sale_id' => $sale_id,
            'cart_first_focus' => $cart_first_focus,
            'misc_settings' => $miscSettings,
            'default_charges' => $defaultCharges,
        ]);
    }

    public function prepareSaleData(Request $request)
    {
        $edit_sale = $request->input('edit_sale');
        $edit_sale_id = $request->input('edit_sale_id');

        $saleData = [
            'store_id' => session('store_id', Auth::user()->store_id),
            'reference_id' => $request->input('return_sale_id'),
            'sale_type' => $request->input('return_sale') ? 'return' : 'sale',
            'contact_id' => $request->input('contact_id'),
            'sale_date' => $request->input('sale_date', Carbon::now()->toDateString()),
            'total_amount' => $request->input('net_total'),
            'discount' => $request->input('discount'),
            'amount_received' => $request->input('amount_received', 0),
            'profit_amount' => $request->input('profit_amount', 0),
            'status' => 'pending',
            'payment_status' => 'pending',
            'note' => $request->input('note'),
            'created_by' => Auth::id(),
            'cart_snapshot' => json_encode($request->input('cartItems')),
        ];

        if ($edit_sale && $edit_sale_id) {
            $sale = Sale::findOrFail($edit_sale_id);
            $sale->update($saleData);

            // Restore stock
            foreach ($sale->saleItems as $item) {
                $product = Product::find($item->product_id);

                if ($product->is_stock_managed) {
                    $restoreQty = $item->quantity + ($item->free_quantity ?? 0);
                    $this->stock->restore($sale->store_id, $item->batch_id, $restoreQty);
                }
            }

            // Update contact balance
            foreach ($sale->transactions as $transaction) {
                if (in_array($transaction->payment_method, ['Account', 'Credit'])) {
                    Contact::where('id', $sale->contact_id)
                        ->increment('balance', $transaction->amount);
                }

                if ($transaction->payment_method == 'Cash') {
                    CashLog::where('reference_id', $transaction->id)->where('source', 'sales')->delete();
                }
            }

            // Remove transactions and sale items
            SaleItem::where('sale_id', $sale->id)->delete();
            Transaction::where('sales_id', $sale->id)->delete();
        } else {
            $sale = Sale::create($saleData);
        }

        return $sale;
    }

    public function checkout(Request $request)
    {
        $amountReceived = $request->input('amount_received', 0);
        $total = $request->input('net_total');
        $cartItems = $request->input('cartItems');
        $charges = $request->input('charges', []);
        $paymentMethod = $request->input('payment_method', 'none');
        $payments = $request->payments;

        DB::beginTransaction();
        try {
            $sale = $this->prepareSaleData($request);

            if ($paymentMethod == 'Cash') {
                Transaction::create([
                    'sales_id' => $sale->id,
                    'store_id' => $sale->store_id,
                    'contact_id' => $sale->contact_id,
                    'transaction_date' => $sale->sale_date, // Current date and time
                    'amount' => $total,
                    'payment_method' => $paymentMethod,
                    'transaction_type' => 'sale'
                ]);
                $sale->status = 'completed';
                $sale->payment_status = 'completed';
                $sale->save();
            } else {
                $amountReceived += $this->payments->recordOrderPayments(PaymentRecorder::SALE, $sale, $payments, $sale->sale_date);

                if ($amountReceived >= $total) {
                    $sale->payment_status = 'completed';
                    $sale->status = 'completed';
                }

                $sale->amount_received = $amountReceived;
                $sale->save();
            }

            // Calculate product line total for base_amount of charges
            $productLineTotal = 0;
            $returnedQuantity = 0;

            foreach ($cartItems as $item) {
                $lineTotal = ($item['quantity'] * ($item['price'] - $item['discount'])) - ($item['flat_discount'] ?? 0);
                $productLineTotal += $lineTotal;
                $returnedQuantity += $item['quantity'];

                $sale_item = SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $item['id'],
                    'batch_id' => $item['batch_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['price'],
                    'unit_cost' => $item['cost'],
                    'discount' => $item['discount'] ?? 0,
                    'flat_discount' => $item['flat_discount'] ?? 0,
                    'sale_date' => $sale->sale_date,
                    'description' => isset($item['category_name']) ? $item['category_name'] : null,
                    'is_free' => isset($item['is_free']) ? $item['is_free'] : 0,
                    'free_quantity' => isset($item['free_quantity']) ? $item['free_quantity'] : 0,
                ]);

                if ($item['is_stock_managed'] == 1) {
                    // Total deduction = sold qty + free qty
                    $deduction = $item['quantity'] + ($item['free_quantity'] ?? 0);

                    if (!$this->stock->deduct($sale->store_id, $item['batch_id'], $deduction)) {
                        DB::rollBack();
                        return response()->json(['error' => 'Stock for product not found in the specified store or batch'], 500);
                    }
                }

                if ($item['product_type'] == 'reload') {
                    $validator = Validator::make($item, [
                        'account_number' => 'required', // Account number must be required when product type is reload
                    ]);

                    if ($validator->fails()) {
                        DB::rollBack();
                        return response()->json([
                            'error' => 'Account number is required for reload product type.',
                            'messages' => $validator->errors(),
                        ], 400);
                    }

                    // Create a ReloadAndBillMeta record with description 'reload'
                    ReloadAndBillMeta::create([
                        'sale_item_id' => $sale_item->id,
                        'transaction_type' => 'reload',
                        'account_number' => $item['account_number'],
                        'commission' => $item['commission'],
                        'additional_commission' => $item['additional_commission'],
                        'description' => $item['product_type'],
                    ]);
                }
            }

            app(ChargeCalculator::class)->apply($sale, $charges, $productLineTotal, $returnedQuantity);

            DB::commit();

            return response()->json(['message' => 'Sale recorded successfully!', 'sale_id' => $sale->id], 201);
        } catch (\Exception $e) {
            // Rollback transaction in case of error
            DB::rollBack();

            $this->logError('Transaction failed', $e);

            // Return error response
            return response()->json(['error' => $this->errorMessage($e)], 500);
        }
    }

    public function customerDisplay()
    {
        return Inertia::render('POS/CustomerDisplay', []);
    }
}
