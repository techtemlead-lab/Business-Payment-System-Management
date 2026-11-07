<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealerRank extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['current_rank' => 'dealer', 'lifetime_purchase_total' => '0.00', 'monthly_purchase_total' => '0.00'];

    protected $casts = [
        'monthly_purchase_total' => 'decimal:2',
        'lifetime_purchase_total' => 'decimal:2',
        'achieved_at' => 'datetime',
        'one_time_incentive_paid' => 'boolean',
        'one_time_incentive_paid_at' => 'datetime',
        'monthly_qualified' => 'boolean',
        'qualification_history' => 'array',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
