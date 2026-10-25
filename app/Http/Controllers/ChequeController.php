<?php

namespace App\Http\Controllers;

use App\Settings\SettingsRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Store;
use App\Models\Cheque;

class ChequeController extends Controller
{
    public function getCheques($filters)
    {
        $settingArray = app(SettingsRepository::class)->all();
        $settingArray['misc_settings'] = json_decode($settingArray['misc_settings'], true);
        $cheque_alert = isset($settingArray['cheque_alert']) ? (int)$settingArray['cheque_alert'] : 3;

        $query = Cheque::query();
        $query->orderByRaw("
            CASE 
                WHEN status = 'pending' THEN 1 
                ELSE 2 
            END
        ")->orderBy('cheque_date', 'asc');

        if (!empty($filters['store']) && $filters['store'] != 0) {
            $query->where('store_id', $filters['store']);
        }

        // Filter by cheque_date range
        if (isset($filters['start_date']) && isset($filters['end_date'])) {
            $query->whereBetween('cheque_date', [$filters['start_date'], $filters['end_date']]);
        }

        // Search by name or description (for `remark` or `name` fields)
        if (!empty($filters['search_query'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search_query']}%")
                    ->orWhere('remark', 'like', "%{$filters['search_query']}%");
            });
        }

        // Filter by status
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            if ($filters['status'] == 'alert') {
                $query->where('status', 'pending')
                    ->where('cheque_date', '<=', now()->addDays($cheque_alert));
            } else $query->where('status', $filters['status']);
        }

        // Filter by bank
        if (!empty($filters['bank'])) {
            $query->where('bank', 'like', "%{$filters['bank']}%");
        }

        // Filter by direction (issued/received)
        if (!empty($filters['direction']) && $filters['direction'] !== 'all') {
            $query->where('direction', $filters['direction']);
        }

        // Filter by amount range
        if (isset($filters['min_amount']) && isset($filters['max_amount'])) {
            $query->whereBetween('amount', [$filters['min_amount'], $filters['max_amount']]);
        }

        $perPage = $filters['per_page'] ?? 100; // Default to 25 items per page

        // Pagination with filters applied
        $results = $query->paginate($perPage);
        $results->appends($filters);
        return $results;
    }


    public function index(Request $request)
    {
        $filters = $request->only(['store', 'start_date', 'end_date', 'search_query', 'status', 'bank', 'direction', 'min_amount', 'max_amount', 'per_page']);
        $stores = Store::all();
        $cheques = $this->getCheques($filters);
        return Inertia::render('Cheque/Cheque', [
            'pageLabel' => 'Cheques',
            'stores' => $stores,
            'cheques' => $cheques,
        ]);
    }

    public function store(Request $request)
    {
        // Validate the incoming request
        $validated = $request->validate([
            'cheque_number' => 'required',
            'cheque_date' => 'required|date',
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric',
            'bank' => 'nullable|string',
            'status' => 'required|string',
            'remark' => 'nullable|string',
            'direction' => 'required|string|in:issued,received',
            'store_id' => 'required|exists:stores,id',
            'issued_date' => 'required|date',
        ]);

        // Create a new cheque record
        $cheque = Cheque::create([
            'cheque_number' => $validated['cheque_number'],
            'cheque_date' => $validated['cheque_date'],
            'name' => $validated['name'],
            'amount' => $validated['amount'],
            'bank' => $validated['bank'],
            'status' => $validated['status'],
            'remark' => $validated['remark'],
            'direction' => $validated['direction'],
            'store_id' => $validated['store_id'],
            'issued_date' => $validated['issued_date'],
        ]);

        // Return JSON response
        return response()->json([
            'status' => 'success',
            'message' => 'Cheque created successfully!',
            'cheque' => $cheque, // Optionally, you can return the created cheque data
        ], 201);
    }

    public function update(Request $request, Cheque $cheque)
    {
        $validated = $request->validate([
            'cheque_number' => 'required',
            'cheque_date' => 'required|date',
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric',
            'bank' => 'nullable|string',
            'status' => 'required|string',
            'remark' => 'nullable|string',
            'direction' => 'required|string|in:issued,received',
            'store_id' => 'required|exists:stores,id',
            'issued_date' => 'required|date',
        ]);

        // Update the cheque record
        $cheque->update([
            'cheque_number' => $validated['cheque_number'],
            'cheque_date' => $validated['cheque_date'],
            'name' => $validated['name'],
            'amount' => $validated['amount'],
            'bank' => $validated['bank'],
            'status' => $validated['status'],
            'remark' => $validated['remark'],
            'direction' => $validated['direction'],
            'store_id' => $validated['store_id'],
            'issued_date' => $validated['issued_date'],
        ]);

        // Return JSON response
        return response()->json([
            'status' => 'success',
            'message' => 'Cheque updated successfully!',
            'cheque' => $cheque, // Optionally return the updated cheque data
        ], 200);
    }

    public function destroy(Cheque $cheque)
    {
        // Soft delete the cheque record
        $cheque->delete();

        // Return JSON response
        return response()->json([
            'status' => 'success',
            'message' => 'Cheque deleted successfully!',
        ], 200);
    }
}
