<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\ProductSizeGroups;
use App\Models\Product;
use App\Support\SiteCustomization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductSizeGroupController extends Controller
{
    public function candidates(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->allows('catalog'), 403);
        $data = $request->validate(['q' => 'nullable|string|max:100']);

        return response()->json(['items' => ProductSizeGroups::candidates($product, trim((string) ($data['q'] ?? '')))]);
    }

    public function store(Request $request, Product $product): RedirectResponse
    {
        abort_unless($request->user()->allows('catalog'), 403);
        $data = $request->validate(['with_product_id' => 'required|integer|min:1']);
        ProductSizeGroups::add($product, (int) $data['with_product_id'], $request->user());
        SiteCustomization::forgetCache();

        return back()->with('success', __('size_groups.flash_added'));
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        abort_unless($request->user()->allows('catalog'), 403);
        $removed = ProductSizeGroups::remove($product, $request->user());
        SiteCustomization::forgetCache();

        return back()->with('success', __($removed ? 'size_groups.flash_removed' : 'size_groups.flash_not_in_group'));
    }
}
