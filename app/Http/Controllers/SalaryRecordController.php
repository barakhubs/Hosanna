<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SalaryRecord;
use App\Models\CashLog;
use Inertia\Inertia;
use App\Models\Employee;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalaryRecordController extends Controller
{
    public function getSalaries($filters)
    {
        $query = SalaryRecord::query();

        $query->select(
            'salary_records.id',
            'salary_records.employee_id',
            'employees.name as employee_name', // Join employees to fetch employee name
            'salary_records.salary_date',
            'salary_records.basic_salary',
            'salary_records.allowances',
            'salary_records.deductions',
            'salary_records.gross_salary',
            'salary_records.net_salary',
            'salary_records.salary_from',
            'salary_records.created_by',
            'salary_records.store_id',
            'stores.name as store_name' // Join stores to fetch store name
        )
            ->join('employees', 'salary_records.employee_id', '=', 'employees.id') // Join with employees table
            ->join('stores', 'salary_records.store_id', '=', 'stores.id') // Join with stores table
            ->orderBy('salary_records.salary_date', 'desc');

        // Apply filters if provided
        if (isset($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }

        if (isset($filters['store_id'])) {
            $query->where('salary_records.store_id', $filters['store_id']);
        }

        if (isset($filters['start_date']) && isset($filters['end_date'])) {
            $query->whereBetween('salary_date', [$filters['start_date'], $filters['end_date']]);
        }

        $perPage = $filters['per_page'] ?? 100;
        $results = $query->paginate($perPage);
        $results->appends($filters);
        return $results;
    }

    public function index(Request $request)
    {
        $filters = $request->only(['employee_id', 'store_id', 'start_date', 'end_date']);
        $salaries = $this->getSalaries($filters);

        $employees = Employee::select('id', 'name')->get();
        $stores = Store::select('id', 'name')->get();

        return Inertia::render('Payroll/Payroll', [
            'salaries' => $salaries,
            'employees' => $employees,
            'stores' => $stores,
            'pageLabel' => 'Salaries',
        ]);
    }

    public function store(Request $request)
    {
        // Validate the incoming request data
        $validatedData = $request->validate([
            'employee_id' => 'required|exists:employees,id', // Assuming you have an employees table
            'salary_date' => 'required|date',
            'basic_salary' => 'required|numeric',
            'allowances' => 'nullable|numeric',
            'deductions' => 'nullable|numeric',
            'gross_salary' => 'nullable|numeric',
            'net_salary' => 'required|numeric',
            'salary_from' => 'required|string', // Change to appropriate validation
            'store_id' => 'required',
            'adjusts_balance'=> 'required',
            'remarks' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {

            // Create a new salary record
            $salaryRecord = SalaryRecord::create($validatedData);

            if ($salaryRecord->salary_from == "Cash Drawer") {
                CashLog::create([
                    'transaction_date' => $salaryRecord->salary_date,  // Use expense_date as transaction_date
                    'transaction_type' => 'cash_out',  // Set the transaction type as 'withdrawal'
                    'reference_id' => $salaryRecord->id,  // The ID of the expense as the reference
                    'amount' => $salaryRecord->net_salary * -1,  // Convert the amount to its opposite value (negative)
                    'source' => 'salary',  // Set source as 'expenses'
                    'description' => $request->employee_name,  // Copy the description
                    'store_id' => $salaryRecord->store_id,  // Store ID from expense
                ]);
            }

            if ($validatedData['adjusts_balance']) {
                $employee = Employee::find($validatedData['employee_id']);
                if ($employee) {
                    // Decrement the balance by the provided amount
                    $employee->decrement('balance', $salaryRecord->net_salary);
                }
            }

            DB::commit();
            return response()->json(['message' => 'Salary added successfully'], 200);
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

    public function delete($id)
    {
        // Find the salary record by ID
        $salaryRecord = SalaryRecord::find($id);

        if (!$salaryRecord) {
            return response()->json(['message' => 'Salary record not found'], 404);
        }

        DB::beginTransaction();
        try {
            // Check if the salary record was created with a "Cash Drawer" payment method
            if ($salaryRecord->salary_from == "Cash Drawer") {
                // Find the associated CashLog entry using reference_id
                $cashLog = CashLog::where('reference_id', $salaryRecord->id)
                    ->where('source', 'salary') // Ensure source is 'salary'
                    ->first();

                if ($cashLog) {
                    // Delete the related CashLog entry
                    $cashLog->delete();
                }
            }

            if ($salaryRecord->adjusts_balance) {
                $employee = Employee::find($salaryRecord->employee_id);
                if ($employee) {
                    $employee->increment('balance', $salaryRecord->net_salary); // Reverse the amount using increment
                }
            }

            // Delete the salary record
            $salaryRecord->delete();

            DB::commit();
            return response()->json(['message' => 'Salary record and related CashLog deleted successfully'], 200);
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
}
