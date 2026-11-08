<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A contest for partners over a set period and metric. Prizes are given by hand.
 *
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 */
class PartnerContest extends Model
{
    /** Confirmed purchase value in taka, number of confirmed orders, and direct partners who joined. */
    public const Metrics = ['purchases', 'orders', 'new_partners'];

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }
}
