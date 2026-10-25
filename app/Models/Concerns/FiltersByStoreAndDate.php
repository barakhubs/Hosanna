<?php

namespace App\Models\Concerns;

/**
 * storeId() and dateFilter() scopes used by the reports.
 * Models set $dateFilterColumn to the column dateFilter() should use.
 */
trait FiltersByStoreAndDate
{
    public function scopeStoreId($query, $storeId)
    {
        if ($storeId !== 'All' && $storeId !== 0) {
            return $query->where('store_id', $storeId);
        }
        return $query;
    }

    public function scopeDateFilter($query, $startDate, $endDate)
    {
        if (!empty($startDate) && !empty($endDate)) {
            return $query->whereBetween($this->dateFilterColumn, [$startDate, $endDate]);
        }
        return $query;
    }
}
