<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchProductStock extends Model
{
    protected $guarded = ['id'];

    protected $attributes = [
        'quantity' => 0,
        'low_stock_at' => 10,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'low_stock_at' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CompanyBranch::class, 'branch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockStatus(bool $paused = false): string
    {
        if ($paused || $this->quantity <= 0) {
            return 'out_of_stock';
        }

        return $this->quantity <= $this->low_stock_at ? 'low_stock' : 'in_stock';
    }
}
