<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Purchase extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['unit_mrp_minor' => 'integer', 'total_mrp_minor' => 'integer', 'amount_paid_minor' => 'integer', 'quantity' => 'integer', 'calculation' => 'array', 'purchased_on' => 'date:Y-m-d'];
    }

    public static function allocationTotals(): array
    {
        $totals = static::where('status', 'recorded')->whereNotNull('ledger_entry_id')->selectRaw('SUM(total_mrp_minor) AS mrp, SUM(company_minor) AS company, SUM(dealer_minor) AS dealer, SUM(pool_minor) AS pool, SUM(allocated_pool_minor) AS allocated, SUM(unallocated_pool_minor) AS unallocated')->first();

        return ['mrp_minor' => (int) $totals->mrp, 'company_minor' => (int) $totals->company, 'dealer_minor' => (int) $totals->dealer, 'pool_minor' => (int) $totals->pool, 'allocated_minor' => (int) $totals->allocated, 'unallocated_minor' => (int) $totals->unallocated];
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }
}
