<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RewardMilestoneProgress extends Model
{
    protected $table = 'reward_milestone_progress';
    protected $guarded = ['id'];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'leg_cap_amount' => 'decimal:2',
        'raw_team_volume' => 'decimal:2',
        'qualifying_volume' => 'decimal:2',
        'achieved' => 'boolean',
        'achieved_at' => 'datetime',
        'claimed' => 'boolean',
        'claimed_at' => 'datetime',
        'leg_breakdown' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
