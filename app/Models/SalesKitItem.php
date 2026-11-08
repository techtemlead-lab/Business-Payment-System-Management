<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One approved sales material: a poster, price list or social post in Bangla or English.
 */
class SalesKitItem extends Model
{
    public const Kinds = ['poster', 'price_list', 'social_post'];

    public const Languages = ['bn', 'en'];

    protected $guarded = ['id'];
}
