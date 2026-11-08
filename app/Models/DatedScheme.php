<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A dated offer shown to partners while they order. Display only: it never changes an order.
 */
class DatedScheme extends Model
{
    public const BenefitTypes = ['free_goods', 'discount'];

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['product_ids' => 'array', 'starts_on' => 'date', 'ends_on' => 'date'];
    }
}
