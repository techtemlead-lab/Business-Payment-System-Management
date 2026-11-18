<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionAllocation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'rate_percentage' => 'decimal:2',
        'cash_basis' => 'decimal:2',
        'amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getMaturationDueAtAttribute(): ?string
    {
        return $this->metadata['maturation_due_at'] ?? null;
    }
}
