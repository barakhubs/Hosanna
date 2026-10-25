<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Models\Charge;
use App\Models\Collection;
use App\Models\Contact;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Store;
use App\Queries\PosProductQuery;
use App\Services\Sales\CheckoutException;
use App\Services\Sales\CheckoutService;
use App\Settings\SettingsRepository;
use App\Support\SqlDialect;
use App\Support\StorageUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class POSController extends Controller
{
    public function __construct(private CheckoutService $checkout)
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

    public function checkout(CheckoutRequest $request)
    {
        try {
            $sale = $this->checkout->checkout(
                $request->all(),
                session('store_id', Auth::user()->store_id),
                Auth::id()
            );

            return response()->json(['message' => 'Sale recorded successfully!', 'sale_id' => $sale->id], 201);
        } catch (CheckoutException $e) {
            return response()->json($e->payload, $e->status);
        } catch (\Exception $e) {
            $this->logError('Transaction failed', $e);

            return response()->json(['error' => $this->errorMessage($e)], 500);
        }
    }

    public function customerDisplay()
    {
        return Inertia::render('POS/CustomerDisplay', []);
    }
}
