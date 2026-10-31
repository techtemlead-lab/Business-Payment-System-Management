<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\CatalogTaxonomy;
use App\Http\Requests\Catalog\DestroyProductCategoryRequest;
use App\Http\Requests\Catalog\StoreProductCategoryRequest;
use App\Http\Requests\Catalog\UpdateProductCategoryRequest;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductCategoryController extends Controller
{
    public function index(Request $request, CatalogTaxonomy $taxonomy): JsonResponse
    {
        $this->permitRead($request);
        $includeInactive = $request->boolean('include_inactive');

        return response()->json([
            'data' => [
                'categories' => $taxonomy->categoriesForEditors(activeOnly: ! $includeInactive),
            ],
        ]);
    }

    public function store(StoreProductCategoryRequest $request, CatalogTaxonomy $taxonomy): RedirectResponse|JsonResponse
    {
        $category = $taxonomy->createCategory($request->validated(), $request->user()->id);
        if ($request->expectsJson()) {
            return response()->json(['data' => $taxonomy->categoryPayload($category->load('subcategories'))], 201);
        }

        return back()->with('success', __('portal.flash_catalog_category_saved', ['name' => $category->name]));
    }

    public function update(UpdateProductCategoryRequest $request, ProductCategory $productCategory, CatalogTaxonomy $taxonomy): RedirectResponse|JsonResponse
    {
        $category = $taxonomy->updateCategory($productCategory, $request->validated(), $request->user()->id);
        if ($request->expectsJson()) {
            return response()->json(['data' => $taxonomy->categoryPayload($category->load('subcategories'))]);
        }

        return back()->with('success', __('portal.flash_catalog_category_updated', ['name' => $category->name]));
    }

    public function destroy(DestroyProductCategoryRequest $request, ProductCategory $productCategory, CatalogTaxonomy $taxonomy): RedirectResponse|JsonResponse
    {
        $data = $request->validated();
        $name = $productCategory->name;
        $taxonomy->deleteCategory(
            $productCategory,
            $data['reason'],
            $request->user()->id,
            (bool) ($data['force'] ?? false),
        );

        if ($request->expectsJson()) {
            return response()->json(['data' => ['deleted' => true, 'name' => $name]]);
        }

        return back()->with('success', __('portal.flash_catalog_category_deleted', ['name' => $name]));
    }

    private function permitRead(Request $request): void
    {
        abort_unless(
            $request->user()?->allows('catalog')
            || $request->user()?->allows('pricing')
            || $request->user()?->allows('inventory'),
            403
        );
    }
}
