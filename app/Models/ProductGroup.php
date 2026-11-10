<?php

namespace App\Models;

use Database\Factories\ProductGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sizes of one product, such as a syrup sold as 100 ml and 300 ml. Only ties products together
 * for display and choosing: each size keeps its own price, stock, batches and reports.
 */
class ProductGroup extends Model
{
    /** @use HasFactory<ProductGroupFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
