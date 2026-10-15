<?php

namespace App\Domain\Pricing;

use App\Domain\Finance\Percentage;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Adapter for the current schema: configured dealer_price and a 40% sub-dealer discount.
 * Replace this adapter when the database owner supplies per-product price policies.
 */
final class CurrentProductPricing implements ProductPricing
{
    public function unitPrice(Product $product, User $buyer): int
    {
        abort_unless(in_array($buyer->role, ['dealer', 'sub_dealer'], true), 403);
        $mrp = Money::cents($product->mrp);
        if ($buyer->role === 'sub_dealer') {
            try {
                $price = Percentage::from('60.00')->of($mrp);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['items' => $exception->getMessage()]);
            }
        } else {
            if ($product->dealer_price === null) {
                throw ValidationException::withMessages(['items' => 'A dealer price has not been configured for '.$product->name.'.']);
            }
            $price = Money::cents($product->dealer_price);
        }
        if ($price <= 0 || $price > $mrp) {
            throw ValidationException::withMessages(['items' => 'The configured purchase price must be positive and cannot exceed MRP.']);
        }

        return $price;
    }
}
