<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Someone in a partner's own customer book. Only that partner can ever read or change the row.
 */
class PartnerCustomer extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['follow_up_on' => 'date'];
    }
}
