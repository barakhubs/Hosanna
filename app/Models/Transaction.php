<?php

namespace App\Models;

use App\Models\Concerns\FiltersByStoreAndDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Userstamps;
use App\Models\CashLog;

class Transaction extends Model
{
    use FiltersByStoreAndDate;
    use HasFactory;
    use SoftDeletes;
    use Userstamps;

    protected $dateFilterColumn = 'transaction_date';

    protected $fillable = [
        'sales_id',          // Unique identifier for the sale
        'store_id',         // ID of the store
        'contact_id',      // ID of the customer
        'transaction_date', // Date and time of the transaction
        'amount',           // Amount paid
        'payment_method',   // Payment method
        'transaction_type',
        'note',
        'parent_id',
    ];

    protected static function booted()
    {
        static::created(function ($transaction) {
            // After a transaction is created, create a related Cash Log
            $transaction->createCashLog();
        });
    }

    public function createCashLog()
    {
        // Check if payment_method is 'cash'
        if ($this->payment_method == 'Cash') {
            $description = '';
            
            // Determine the transaction type and amount based on whether it's a refund
            if ($this->amount < 0) {
                // If amount is negative, it's a refund, so it's 'cash_out' in the CashLog
                $transactionType = 'cash_out';
                $description = 'Refund';
            } else {
                // Normal cash transaction
                $transactionType = 'cash_in';
            }

            // Create the cash log entry using the values from the transaction
            CashLog::create([
                'transaction_date' => $this->transaction_date,  // Use transaction_date as transaction_date
                'transaction_type' => $transactionType,  // Set the transaction type (cash_in or cash_out)
                'reference_id' => $this->id,  // Reference the transaction ID
                'amount' => $this->amount,  // Use the amount as is (negative for refunds)
                'contact_id'=>$this->contact_id,
                'source' => 'sales',  // Source is 'transactions'
                'description' => $description,  // Set description based on the transaction type
                'store_id' => $this->store_id,  // Store ID from transaction
            ]);
        }
    }

    // Relationship back to sales
    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sales_id');
    }
}
