<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\CatalogTaxonomy;
use App\Http\Requests\Catalog\DestroyProductUnitRequest;
use App\Http\Requests\Catalog\StoreProductUnitRequest;
use App\Http\Requests\Catalog\UpdateProductUnitRequest;
use App\Models\ProductUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductUnitController extends Controller
{
    public function index(Request $request, CatalogTaxonomy $taxonomy): JsonResponse
    {
        $this->permitRead($request);
        $includeInactive = $request->boolean('include_inactive');

        return response()->json([
            'data' => [
                'units' => $taxonomy->unitsForEditors(activeOnly: ! $includeInactive),
            ],
        ]);
    }

    public function store(StoreProductUnitRequest $request, CatalogTaxonomy $taxonomy): RedirectResponse|JsonResponse
    {
        $unit = $taxonomy->createUnit($request->validated(), $request->user()->id);
        if ($request->expectsJson()) {
            return response()->json(['data' => $taxonomy->unitPayload($unit)], 201);
        }

        return back()->with('success', __('portal.flash_catalog_unit_saved', ['name' => $unit->name]));
    }

    public function update(UpdateProductUnitRequest $request, ProductUnit $productUnit, CatalogTaxonomy $taxonomy): RedirectResponse|JsonResponse
    {
        $unit = $taxonomy->updateUnit($productUnit, $request->validated(), $request->user()->id);
        if ($request->expectsJson()) {
            return response()->json(['data' => $taxonomy->unitPayload($unit)]);
        }

        return back()->with('success', __('portal.flash_catalog_unit_updated', ['name' => $unit->name]));
    }

    public function destroy(DestroyProductUnitRequest $request, ProductUnit $productUnit, CatalogTaxonomy $taxonomy): RedirectResponse|JsonResponse
    {
        $name = $productUnit->name;
        $taxonomy->deleteUnit($productUnit, $request->validated('reason'), $request->user()->id);

        if ($request->expectsJson()) {
            return response()->json(['data' => ['deleted' => true, 'name' => $name]]);
        }

        return back()->with('success', __('portal.flash_catalog_unit_deleted', ['name' => $name]));
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
