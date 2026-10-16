<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionAllocation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'rate_percentage' => 'decimal:2',
        'cash_basis' => 'decimal:2',
        'amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getMaturationDueAtAttribute(): ?string
    {
        return $this->metadata['maturation_due_at'] ?? null;
    }
}
