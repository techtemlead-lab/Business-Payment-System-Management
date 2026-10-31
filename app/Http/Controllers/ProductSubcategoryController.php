<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\CatalogTaxonomy;
use App\Http\Requests\Catalog\DestroyProductSubcategoryRequest;
use App\Http\Requests\Catalog\StoreProductSubcategoryRequest;
use App\Http\Requests\Catalog\UpdateProductSubcategoryRequest;
use App\Models\ProductSubcategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class ProductSubcategoryController extends Controller
{
    public function store(StoreProductSubcategoryRequest $request, CatalogTaxonomy $taxonomy): RedirectResponse|JsonResponse
    {
        $subcategory = $taxonomy->createSubcategory($request->validated(), $request->user()->id);
        if ($request->expectsJson()) {
            return response()->json(['data' => $taxonomy->subcategoryPayload($subcategory)], 201);
        }

        return back()->with('success', __('portal.flash_catalog_subcategory_saved', ['name' => $subcategory->name]));
    }

    public function update(UpdateProductSubcategoryRequest $request, ProductSubcategory $productSubcategory, CatalogTaxonomy $taxonomy): RedirectResponse|JsonResponse
    {
        $subcategory = $taxonomy->updateSubcategory($productSubcategory, $request->validated(), $request->user()->id);
        if ($request->expectsJson()) {
            return response()->json(['data' => $taxonomy->subcategoryPayload($subcategory)]);
        }

        return back()->with('success', __('portal.flash_catalog_subcategory_updated', ['name' => $subcategory->name]));
    }

    public function destroy(DestroyProductSubcategoryRequest $request, ProductSubcategory $productSubcategory, CatalogTaxonomy $taxonomy): RedirectResponse|JsonResponse
    {
        $name = $productSubcategory->name;
        $taxonomy->deleteSubcategory($productSubcategory, $request->validated('reason'), $request->user()->id);

        if ($request->expectsJson()) {
            return response()->json(['data' => ['deleted' => true, 'name' => $name]]);
        }

        return back()->with('success', __('portal.flash_catalog_subcategory_deleted', ['name' => $name]));
    }
}
