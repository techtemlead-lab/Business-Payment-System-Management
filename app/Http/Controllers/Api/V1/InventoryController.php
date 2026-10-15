<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\StockService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\AdjustStockRequest;
use Illuminate\Http\JsonResponse;

class InventoryController extends Controller
{
    public function store(AdjustStockRequest $request, StockService $service): JsonResponse
    {
        $product = $service->adjust($request->user(), $request->validated());

        return response()->json(['data' => $product->only(['id', 'sku', 'name', 'stock', 'low_stock_at'])]);
    }
}
