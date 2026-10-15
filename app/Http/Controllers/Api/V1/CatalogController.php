<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Pricing\ProductPricing;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    public function index(Request $request, ProductPricing $pricing): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true) || $user->staff(), 403);
        $data = $request->validate(['q' => 'nullable|string|max:100', 'per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);
        $products = Product::query()->where('active', true)
            ->when(! empty($data['q']), fn ($q) => $q->where('name', 'like', '%'.$data['q'].'%'))
            ->orderBy('name')->orderBy('id')->paginate($data['per_page'] ?? 12)->withQueryString();
        $products->through(function (Product $product) use ($user, $pricing): array {
            $price = null;
            if (! $user->staff()) {
                try {
                    $price = Money::decimal($pricing->unitPrice($product, $user));
                } catch (ValidationException) {
                    $price = null;
                }
            }

            return [
                ...$product->only(['id', 'sku', 'name', 'category', 'description', 'unit', 'mrp', 'stock', 'low_stock_at']),
                ...$product->media(), 'price' => $price, 'currency' => 'BDT',
                ...($user->allows('cost_read') ? ['cost_price' => $product->cost_price] : []),
            ];
        });

        return response()->json($products);
    }
}
