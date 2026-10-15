<?php

namespace App\Domain\Pricing;

use App\Models\Product;
use App\Models\User;

interface ProductPricing
{
    /**
     * Resolve the server-owned purchase price in integer paisa.
     *
     * The schema owner can replace the current adapter with effective-dated,
     * per-product dealer/sub-dealer prices without changing order or catalog APIs.
     */
    public function unitPrice(Product $product, User $buyer): int;
}
