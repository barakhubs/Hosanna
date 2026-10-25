<?php

namespace App\Models;

use App\Models\Concerns\FiltersByStoreAndDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Userstamps;
use App\Models\CashLog;

class PurchaseTransaction extends Model
{
    use FiltersByStoreAndDate;
    use HasFactory;
    use SoftDeletes;
    use Userstamps;

    protected $dateFilterColumn = 'transaction_date';

    protected $fillable = [
        'store_id',
        'contact_id',
        'transaction_date',
        'amount',
        'payment_method',
        'purchase_id',
        'transaction_type',
        'note',
        'parent_id',
    ];

    // Boot method to create CashLog entry after a PurchaseTransaction is created
    protected static function booted()
    {
        static::created(function ($purchaseTransaction) {
            // After a purchase transaction is created, create a related Cash Log
            $purchaseTransaction->createCashLog();
        });
    }

    // Create CashLog entry based on PurchaseTransaction logic
    public function createCashLog()
    {
        // Check if payment_method is 'cash'
        if ($this->payment_method == 'Cash') {
            $description = '';

            // Determine the transaction type based on the amount
            if ($this->amount < 0) {
                // If amount is negative, it's a refund (cash comes back to us), so it's 'cash_in'
                $transactionType = 'cash_in';
                $description = 'Refund';
                $amount = abs($this->amount);
            } else {
                // Normal cash payment to vendor (outflow of cash), so it's 'cash_out'
                $transactionType = 'cash_out';
                $amount = -$this->amount;
            }

            // Create the cash log entry using the values from the purchase transaction
            CashLog::create([
                'transaction_date' => $this->transaction_date,  // Use transaction_date as transaction_date
                'transaction_type' => $transactionType,  // Set the transaction type (cash_in or cash_out)
                'reference_id' => $this->id,  // Reference the purchase transaction ID
                'amount' => $amount,  // Use the amount as is (negative for purchases, positive for refund)
                'contact_id' => $this->contact_id,  // Add contact_id from purchase transaction
                'source' => 'purchases',  // Source is 'purchases'
                'description' => $description,  // Set description based on the transaction type
                'store_id' => $this->store_id,  // Store ID from purchase transaction
            ]);
        }
    }
}
