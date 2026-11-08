<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\PurchasingService;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchasingController extends Controller
{
    public function storeSupplier(Request $request, PurchasingService $purchasing): RedirectResponse
    {
        $supplier = $purchasing->saveSupplier($request->user(), $this->validatedSupplier($request));

        return back()->with('success', __('purchasing.flash_supplier_saved', ['name' => $supplier->name]));
    }

    public function updateSupplier(Request $request, Supplier $supplier, PurchasingService $purchasing): RedirectResponse
    {
        $purchasing->saveSupplier($request->user(), $this->validatedSupplier($request), $supplier);

        return back()->with('success', __('purchasing.flash_supplier_saved', ['name' => $supplier->name]));
    }

    public function storeOrder(Request $request, PurchasingService $purchasing): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'branch_id' => ['required', 'integer', 'exists:company_branches,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->where('active', true)],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);
        $order = $purchasing->createOrder($request->user(), $data);

        return back()->with('success', __('purchasing.flash_order_created', ['number' => $order->displayNumber()]));
    }

    public function sendOrder(Request $request, PurchaseOrder $purchaseOrder, PurchasingService $purchasing): RedirectResponse
    {
        $purchasing->sendOrder($request->user(), $purchaseOrder);

        return back()->with('success', __('purchasing.flash_order_sent', ['number' => $purchaseOrder->displayNumber()]));
    }

    public function cancelOrder(Request $request, PurchaseOrder $purchaseOrder, PurchasingService $purchasing): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $purchasing->cancelOrder($request->user(), $purchaseOrder, $data['reason']);

        return back()->with('success', __('purchasing.flash_order_cancelled', ['number' => $purchaseOrder->displayNumber()]));
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder, PurchasingService $purchasing): RedirectResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.purchase_order_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.batch_number' => ['required', 'string', 'max:60'],
            'lines.*.expires_on' => ['required', 'date', 'after_or_equal:'.now('Asia/Dhaka')->toDateString()],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
        ], [
            'lines.*.expires_on.after_or_equal' => __('portal.err_stock_expiry_past'),
        ]);
        $purchasing->receive($request->user(), $purchaseOrder, $data);

        return back()->with('success', __('purchasing.flash_goods_received', ['number' => $purchaseOrder->displayNumber()]));
    }

    /**
     * @return array{name:string,contact?:?string,notes?:?string}
     */
    private function validatedSupplier(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'contact' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
