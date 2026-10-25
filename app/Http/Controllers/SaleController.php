<?php

namespace App\Http\Controllers;

use App\Services\Sales\SaleNotifier;
use App\Services\Sales\ReceiptTextRenderer;
use App\Queries\SaleReceiptQuery;
use App\Settings\SettingsRepository;
use App\Support\SqlDialect;
use App\Support\StorageUrl;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Contact;
use App\Models\User;
use App\Services\Sales\SaleReversalService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SaleController extends Controller
{
    public function __construct(private SaleReceiptQuery $receipts)
    {
    }

    public function getSales($filters)
    {

        $query = Sale::query();
        $query->select(
            'sales.id',
            'contact_id',            // Customer ID
            'sale_date',              // Sale date
            'total_amount',           // Total amount (Total amount after discount [net_total - discount])
            'discount',                // Discount
            'amount_received',         // Amount received
            'profit_amount',          // Profit amount
            'status',
            'payment_status',
            'contacts.name',
            'contacts.balance',
            'store_id',
            'invoice_number',
            'sale_type',
            'sales.created_at',
        )
            ->leftJoin('contacts', 'sales.contact_id', '=', 'contacts.id')
            ->orderBy('sales.id', 'desc');

        if (isset($filters['contact_id'])) {
            $query->where('contact_id', $filters['contact_id']);
        }

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        // if (!isset($filters['start_date']) || !isset($filters['end_date'])) {
        //     $today = now()->format('Y-m-d'); // Format current date to 'Y-m-d'
        //     // dd($today);
        //     $filters['start_date'] = $filters['start_date'] ?? $today;
        //     $filters['end_date'] = $filters['end_date'] ?? $today;
        // }

        if (($filters['status'] ?? null) !== 'pending' && isset($filters['start_date']) && isset($filters['end_date'])) {
            $query->whereBetween('sale_date', [$filters['start_date'], $filters['end_date']]);
        }

        if (isset($filters['query'])) {
            $query->where('invoice_number', 'LIKE', '%' . $filters['query'] . '%');
        }

        $perPage = $filters['per_page'] ?? 100;
        $results = $query->paginate($perPage);
        $results->appends($filters);
        return $results;
    }

    public function index(Request $request)
    {
        $filters = $request->only(['contact_id', 'start_date', 'end_date', 'status', 'query', 'per_page']);
        $sales = $this->getSales($filters);
        $contacts = Contact::select('id', 'name', 'balance')->customers()->get();

        return Inertia::render('Sale/Sale', [
            'sales' => $sales,
            'contacts' => $contacts,
            'pageLabel' => 'Sales',
        ]);
    }

    public function receipt($id)
    {
        $imageUrl = StorageUrl::prefix('');

        $settingArray = app(SettingsRepository::class)->all();
        $settingArray['shop_logo'] = $imageUrl . $settingArray['shop_logo'];

        $sale = $this->receipts->sales([
            'sales.id',
            'contact_id',            // Customer ID
            'sale_date',              // Sale date
            'total_amount',           // Total amount (Total amount after discount [net_total - discount])
            'total_charge_amount',    // Total charge amount
            'discount',                // Discount
            'amount_received',         // Amount received
            'status',                  // Sale status
            'stores.address',
            'contacts.name', // Customer name from contacts
            'contacts.whatsapp',
            'sales.created_by',
            'invoice_number',
            'stores.sale_prefix',
            'stores.contact_number',
            'sales.created_at',
        ])
            ->where('sales.id', $id)
            ->first();

        if (!$sale) {
            abort(404); // This will trigger the 404 error page
        }

        $user = User::find($sale->created_by);

        $salesItems = $this->receipts->items([
            'sale_items.quantity',
            'sale_items.unit_price',
            'sale_items.discount',
            'sale_items.flat_discount',
            'sale_items.free_quantity',
            'sale_items.charge_id',
            'sale_items.item_type',
            'sale_items.description',
            'sale_items.charge_type',
            'sale_items.rate_value',
            'sale_items.rate_type',
            'products.name',
        ])
            ->where('sale_items.sale_id', $id)
            ->get();

        return Inertia::render('Sale/Receipt', [
            'sale' => $sale,
            'salesItems' => $salesItems,
            'settings' => $settingArray,
            'user_name' => $user->name,
        ]);
    }

    public function getSoldItems($filters)
    {
        $query = SaleItem::query();
        $query->select(
            'sale_items.id',
            'sale_items.sale_id',
            'sale_items.product_id',
            'sale_items.quantity',
            'sale_items.unit_price',
            'sale_items.unit_cost',
            'sale_items.discount',
            'sale_items.flat_discount',
            'products.name as product_name',
            'products.barcode',
            'sales.sale_date',
            'contacts.name as contact_name',
            'contacts.balance',

            DB::raw('((unit_price - sale_items.discount - unit_cost) * sale_items.quantity) as profit'),
        )
            ->join('products', 'sale_items.product_id', '=', 'products.id')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->join('contacts', 'sales.contact_id', '=', 'contacts.id')
            ->orderBy('sales.id', 'desc');

        if (isset($filters['item_type'])) {
            if ($filters['item_type'] == 'free') {
                $query->whereRaw('(unit_price - sale_items.discount) = 0');
            }
            if ($filters['item_type'] == 'regular') {
                $query->whereRaw('(unit_price - sale_items.discount) > 0');
            }
            if ($filters['item_type'] == 'return') {
                $query->where('sale_items.quantity', '<', 0);
            }
        }

        if (isset($filters['contact_id'])) {
            $query->where('sales.contact_id', $filters['contact_id']);
        }

        if (isset($filters['start_date']) && isset($filters['end_date'])) {
            $query->whereBetween('sales.sale_date', [$filters['start_date'], $filters['end_date']]);
        }

        if (isset($filters['query'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('products.name', 'LIKE', '%' . $filters['query'] . '%')
                    ->orWhere('products.barcode', 'LIKE', '%' . $filters['query'] . '%');
            });
        }

        $perPage = $filters['per_page'] ?? 100;
        $results = $query->paginate($perPage);
        $results->appends($filters);
        return $results;
    }

    public function soldItemSummary(Request $request)
    {
        $filters = $request->only(['start_date', 'end_date']);

        if (empty($filters['start_date']) && empty($filters['end_date'])) {
            $filters['start_date'] = date('Y-m-d');
            $filters['end_date'] = date('Y-m-d');
        }

        $soldItems = SaleItem::with('product')->select('product_id', DB::raw('sum(quantity) as total_quantity'))
            ->groupBy('product_id');

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $soldItems = $soldItems->whereBetween('sale_date', [$filters['start_date'], $filters['end_date']]);
        }
        $soldItems = $soldItems->get();
        return Inertia::render('SoldItem/SoldItemSummary', [
            'sold_items' => $soldItems,
            'pageLabel' => 'Sold Items',
        ]);
    }

    public function solditems(Request $request)
    {
        $filters = $request->only(['contact_id', 'start_date', 'end_date', 'per_page', 'order_by', 'query', 'item_type', 'is_report']);
        $soldItems = $this->getSoldItems($filters);
        $contacts = Contact::select('id', 'name', 'balance')->customers()->get();
        return Inertia::render('SoldItem/SoldItem', [
            'sold_items' => $soldItems,
            'contacts' => $contacts,
            'pageLabel' => 'Sold Items',
        ]);
    }

    public function pendingSalesReceipt(Request $request, $contact_id)
    {
        $settingArray = app(SettingsRepository::class)->all();
        $columns = [
            'sales.id',
            'sale_date',              // Sale date
            'total_amount',           // Total amount (Total amount after discount [net_total - discount])
            'discount',                // Discount
            'amount_received',         // Amount received
            'status',                  // Sale status
            'stores.address',
            'contacts.name',
            'contacts.whatsapp',
            'contacts.balance',
            'invoice_number',
            'stores.sale_prefix',
            'stores.contact_number',
            'sales.created_at',
        ];

        $sales = $this->receipts->sales($columns)
            ->where('sales.contact_id', $contact_id) // Filter by contact_id
            ->where('sales.status', 'pending')
            ->get(); // Fetch all matching sales

        $saleIds = $sales->pluck('id')->toArray();

        // Fetch return sale for the pending sales
        $completedSales = $this->receipts->sales($columns)
            ->where('sales.contact_id', $contact_id)
            ->whereIn('sales.reference_id', $saleIds)
            ->get();

        $sales = $sales->merge($completedSales);

        if ($sales->isEmpty()) {
            return Inertia::render('Sale/Receipt', [
                'sale' => '',
                'salesItems' => '',
                'settings' => $settingArray,
                'credit_sale' => true,
            ]);
        }

        // Fetch all sale items related to the fetched sales
        $dateFormat = SqlDialect::concat("' ['", SqlDialect::date('sale_items.sale_date'), "'] - '", 'products.name');

        $salesItems = $this->receipts->items([
            'sale_items.quantity',
            'sale_items.unit_price',
            'sale_items.discount',
            'sale_items.flat_discount',
            'sale_items.free_quantity',
            'sale_items.charge_id',
            'sale_items.item_type',
            'sale_items.description',
            'sale_items.charge_type',
            'sale_items.rate_value',
            'sale_items.rate_type',
            DB::raw("$dateFormat as name"),
            'sale_items.sale_id', // Include sale_id for mapping
        ])
            ->whereIn('sale_items.sale_id', $sales->pluck('id')) // Fetch only items for the selected sales
            ->get();

        $mergedSale = [
            'id' => 'merged', // Use a placeholder ID for the merged sale
            'sale_date' => now(), // Set the current date for the merged sale
            'total_amount' => $sales->sum('total_amount'), // Sum of total amounts
            'discount' => $sales->sum('discount'), // Sum of discounts
            'amount_received' => $sales->sum('amount_received'), // Sum of amounts received
            'address' => $sales->first()->address, // Use the first sale's address
            'name' => $sales->first()->name . ' | Balance: ' . $sales->first()->balance, // Use the first sale's customer name
            'created_by' => $sales->first()->created_by, // Use the first sale's creator
            'invoice_number' => 'Merged-' . uniqid(), // Generate a unique invoice number for the merged sale
            'sale_prefix' => '', // Use the first sale's prefix
            'contact_number' => $sales->first()->contact_number, // Use the first sale's contact number
            'created_at' => now(), // Set current timestamp for the merged sale
            'balance' => $sales->first()->balance,
            'whatsapp' => $sales->first()->whatsapp
        ];

        return Inertia::render('Sale/Receipt', [
            'sale' => $mergedSale,
            'salesItems' => $salesItems,
            'settings' => $settingArray,
            'credit_sale' => true,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        /** @var \App\Models\User */
        $user = Auth::user();
        if (!$user->hasRole('admin')) {
            return response()->json(['error' => 'You are not authorized to delete a sale'], 403);
        }
        $today = Carbon::today();
        $sale = Sale::find($id);
        if (!$sale) {
            return response()->json(['error' => 'Sale not found'], 404);
        }

        if (!$sale->created_at->isSameDay($today)) {
            return response()->json(['error' => 'Only sales created today can be deleted'], 403);
        }

        DB::beginTransaction();
        try {
            $sale = Sale::findOrFail($id);
            app(SaleReversalService::class)->delete($sale, Auth::id());
            DB::commit();

            app(SaleNotifier::class)->deleted($sale);

            return response()->json(['success' => 'Sale deleted successfully'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logError('Sale delete failed', $e);
            return response()->json(['error' => $this->errorMessage($e)], 500);
        }
    }

    public function sendNotification($id)
    {
        $sale = Sale::select('sales.*', 'users.name as created_by')
            ->leftJoin('users', 'sales.created_by', '=', 'users.id')
            ->where('sales.id', $id)
            ->first();
        if (!app(SaleNotifier::class)->created($sale)) {
            return response()->json(['error' => 'Admin mail settings not found'], 404);
        }

        return response()->json(['success' => 'Notification sent successfully'], 200);
    }

    // api: /api/receipt-text-raw/{id}
    public function apiReceipt(Request $request, $id)
    {
        $settingArray = app(SettingsRepository::class)->all();
        $settingArray['shop_logo'] = 'public/' . $settingArray['shop_logo'];
        $isJson = $request->input('isJson');

        $sale = $this->receipts->sales([
            'sales.id',
            'contact_id',
            'sale_date',
            'sales.created_at',
            'total_amount',
            'discount',
            'amount_received',
            'status',
            'stores.address as store_address',
            'stores.sale_prefix',
            'stores.contact_number',
            'contacts.name as client_name',
            'sales.created_by',
            'invoice_number',
        ])
            ->where('sales.id', $id)
            ->first();

        if (!$sale) {
            return response()->json(['error' => 'Sale not found'], 404);
        }

        $user = User::find($sale->created_by);

        $saleItems = $this->receipts->items([
            'sale_items.quantity',
            'sale_items.unit_price',
            'sale_items.discount',
            'sale_items.flat_discount',
            'sale_items.free_quantity',
            'products.name as product_name',
        ])
            ->where('sale_items.sale_id', $id)
            ->get();

        // $text = app(ReceiptTextRenderer::class)->productLine($sale, $user, $saleItems, $settingArray);
        $text = app(ReceiptTextRenderer::class)->productSided($sale, $user, $saleItems, $settingArray);

        if ($isJson) {
            return response()->json([
                'sale' => $sale,
                'siteLogo' => $settingArray['shop_logo'],
                'rawText' => $text,
            ], 200);
        }

        return response($text, 200)
            ->header('Content-Type', 'text/plain');
    }
}
