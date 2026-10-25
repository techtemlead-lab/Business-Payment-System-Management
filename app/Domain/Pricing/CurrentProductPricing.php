<?php

namespace App\Domain\Pricing;

use App\Domain\Finance\CalculationMessage;
use App\Domain\Finance\Percentage;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Product-specific partner prices; legacy sub-dealer records retain a 40% discount. */
final class CurrentProductPricing implements ProductPricing
{
    public function unitPrice(Product $product, User $buyer): int
    {
        abort_unless(in_array($buyer->role, ['dealer', 'sub_dealer'], true), 403);
        $mrp = Money::cents($product->mrp);
        if ($buyer->role === 'sub_dealer') {
            try {
                $price = $product->sub_dealer_price !== null ? Money::cents($product->sub_dealer_price) : Percentage::from('60.00')->of($mrp, 'half_up');
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['items' => CalculationMessage::localize($exception->getMessage())]);
            }
        } else {
            if ($product->dealer_price === null) {
                throw ValidationException::withMessages(['items' => __('portal.err_price_missing_dealer', ['product' => $product->name])]);
            }
            $price = Money::cents($product->dealer_price);
        }
        if ($price <= 0 || $price > $mrp) {
            throw ValidationException::withMessages(['items' => __('portal.err_price_positive_mrp')]);
        }

        return $price;
    }
}
