<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
