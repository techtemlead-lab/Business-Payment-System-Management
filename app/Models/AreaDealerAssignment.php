<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AreaDealerAssignment extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'monthly_quota' => 'decimal:2',
        'current_month_purchase' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function placeCode(): BelongsTo
    {
        return $this->belongsTo(PlaceCode::class);
    }
}
