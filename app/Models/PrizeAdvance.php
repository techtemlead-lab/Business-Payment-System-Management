<?php

namespace App\Models;

use App\Domain\Finance\PrizeAdvances;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bike or car prize paid to a partner in advance out of that prize's fund: money the partner owes the company until
 * the real prize is set against it. Written only by {@see PrizeAdvances}.
 */
class PrizeAdvance extends Model
{
    public const StatusOpen = 'open';

    public const StatusSettled = 'settled';

    public const StatusRecovered = 'recovered';

    public const StatusWrittenOff = 'written_off';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'settled_amount' => 'decimal:2',
        'closed_amount' => 'decimal:2',
        'given_on' => 'date',
        'settled_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_id');
    }
}
