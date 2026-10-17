<?php

namespace App\Http\Controllers;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\DueAdjustments;
use App\Domain\Orders\OrderService;
use App\Domain\Staff\StaffOperations;
use App\Domain\Staff\TerritoryAccess;
use App\Http\Requests\Backend\StoreOrderRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class FieldSalesController extends Controller
{
    public function territory(Request $request, TerritoryAccess $territories): Response
    {
        $data = $request->validate(['staff_id' => 'required|integer|exists:users,id', 'level' => 'required|in:division,district,upazila,union',
            'division' => 'required|string|max:80', 'district' => 'nullable|string|max:80', 'upazila' => 'nullable|string|max:80', 'union' => 'nullable|string|max:80']);

        return $this->done($request, ['id' => $territories->assign($request->user(), $data)]);
    }

    public function removeTerritory(Request $request, int $id): Response
    {
        abort_unless($request->user()->allows('staff_admin'), 403);
        $data = $request->validate(['reason' => FinanceOperationsController::Reason]);
        DB::transaction(function () use ($request, $id, $data): void {
            CommissionWallet::lock();
            abort_unless(DB::table('staff_territories')->where('id', $id)->exists(), 404);
            DB::table('staff_territories')->where('id', $id)->update(['is_active' => false, 'territory_key' => null, 'updated_at' => now()]);
            Audit::record($request->user()->id, 'staff.territory_removed', (string) $id, ['reason' => $data['reason']]);
        }, 3);

        return $this->done($request);
    }

    public function target(Request $request, StaffOperations $operations): Response
    {
        $data = $request->validate(['staff_id' => 'required|integer|exists:users,id', 'period_month' => 'required|date_format:Y-m',
            'target' => [...FinanceOperationsController::Amount, 'gt:0'], 'bonus' => FinanceOperationsController::Amount, 'version' => 'nullable|integer|min:1']);

        return $this->done($request, ['id' => $operations->target($request->user(), $data)]);
    }

    public function application(Request $request, StaffOperations $operations): Response
    {
        $data = $request->validate(['name' => 'required|string|max:150', 'email' => 'required|email|max:254', 'phone' => 'required|string|max:30',
            'role' => 'required|in:dealer,sub_dealer', 'division' => 'required|string|max:80', 'district' => 'required|string|max:80',
            'upazila' => 'nullable|string|max:80', 'union' => 'nullable|string|max:80', 'area_code' => 'nullable|string|max:40', 'sponsor_id' => 'nullable|integer|exists:users,id']);

        return $this->done($request, ['id' => $operations->apply($request->user(), $data)]);
    }

    public function reviewApplication(Request $request, string $id, StaffOperations $operations): Response
    {
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'reason' => FinanceOperationsController::Reason]);
        $operations->review($request->user(), $id, $data['decision'], $data['reason']);

        return $this->done($request);
    }

    public function order(Request $request, TerritoryAccess $territories, OrderService $orders): Response
    {
        $data = $request->validate([...(new StoreOrderRequest)->rules(), 'user_id' => 'required|integer|exists:users,id']);
        $user = $territories->assertPartner($request->user(), (int) $data['user_id'], 'sr_orders');
        unset($data['user_id']);
        $order = $orders->create($user, $data, $request->user());

        return $this->done($request, ['id' => $order->id, 'number' => $order->number]);
    }

    public function show(Request $request, Order $order, TerritoryAccess $territories): \Inertia\Response|JsonResponse
    {
        $territories->assertPartner($request->user(), $order->user_id, 'sr_orders');
        $order->load(['lines', 'user:id,name,dealer_code']);
        $safeOrder = [...(new OrderResource($order))->resolve($request), 'user' => $order->user->only(['id', 'name', 'dealer_code'])];
        $data = ['order' => $safeOrder, 'backUrl' => route('company.operations', ['section' => 'field-sales']),
            'outstanding' => $order->outstanding(), 'adjustments' => app(DueAdjustments::class)->history($order),
            'claims' => DB::table('payment_claims')->where('order_id', $order->id)->get(['id', 'channel', 'reference', 'amount', 'status', 'reason', 'created_at']),
            'events' => DB::table('order_events')->where('order_id', $order->id)->orderBy('id')->get(['id', 'action', 'metadata', 'created_at']),
            'shipments' => DB::table('shipments')->where('order_id', $order->id)->orderBy('id')->get(['id', 'courier', 'tracking', 'eta', 'status', 'lines', 'delivered_at', 'created_at'])];

        return $request->expectsJson() ? response()->json(['data' => $data]) : Inertia::render('OrderDetail', $data);
    }

    /** @param array<string, mixed> $data */
    private function done(Request $request, array $data = []): Response
    {
        return $request->expectsJson() ? response()->json(['data' => $data]) : back()->with('success', 'Sales operation recorded successfully.');
    }
}
