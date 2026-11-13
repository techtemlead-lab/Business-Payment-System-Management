<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerAge;
use App\Domain\Orders\OrderPaymentPrompt;
use App\Domain\Orders\OrderService;
use App\Domain\Staff\StaffOperations;
use App\Domain\Staff\TerritoryAccess;
use App\Http\Requests\Backend\StoreOrderRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use App\Support\ReasonFields;
use App\Support\TransactionReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class FieldSalesController extends Controller
{
    public function territory(Request $request, TerritoryAccess $territories): Response
    {
        $data = $request->validate(['staff_id' => 'required|integer|exists:users,id', 'level' => 'nullable|in:division,district,upazila,union',
            'place_code' => 'required|string|max:32'], ['place_code.required' => __('portal.place_code_required')]);

        return $this->done($request, ['id' => $territories->assign($request->user(), $data)], 'portal.flash_sr_area_saved');
    }

    public function removeTerritory(Request $request, int $id, TerritoryAccess $territories): Response
    {
        $data = $request->validate(['reason' => ReasonFields::rule('staff.territory_remove', 1000, 5)]);
        $territories->remove($request->user(), $id, ReasonFields::text($data['reason'] ?? null));

        return $this->done($request, [], 'portal.flash_sr_area_removed');
    }

    public function target(Request $request, StaffOperations $operations): Response
    {
        $data = $request->validate(['staff_id' => 'required|integer|exists:users,id', 'period_month' => 'required|date_format:Y-m',
            'target' => [...FinanceOperationsController::Amount, 'gt:0'], 'bonus' => FinanceOperationsController::Amount, 'version' => 'nullable|integer|min:1']);

        return $this->done($request, ['id' => $operations->target($request->user(), $data)], 'portal.flash_sr_target_saved');
    }

    public function application(Request $request, StaffOperations $operations): Response
    {
        $data = $request->validate(['name' => 'required|string|max:150', 'email' => 'required|email|max:254', 'phone' => 'required|string|max:30',
            'role' => 'required|in:dealer,sub_dealer', 'place_code' => 'required|string|max:32', 'sponsor_id' => 'nullable|integer|exists:users,id',
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', PartnerAge::rule()]], ['place_code.required' => __('portal.place_code_required')]);

        return $this->done($request, ['id' => $operations->apply($request->user(), $data)]);
    }

    public function reviewApplication(Request $request, string $id, StaffOperations $operations): Response
    {
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'reason' => ReasonFields::rule('partner.application_review', 1000, 5),
            'place_code' => 'nullable|string|max:32', 'sponsor_code' => 'nullable|string|max:40',
            'directory_visible' => 'sometimes|boolean', 'phone_share_reason' => 'nullable|string|max:500']);
        $corrections = $request->has('sponsor_code') ? ['sponsor_code' => $data['sponsor_code'] ?? null] : [];
        $choices = ['directory_visible' => $request->boolean('directory_visible'), 'phone_share_reason' => $data['phone_share_reason'] ?? null];
        $operations->review($request->user(), $id, $data['decision'], ReasonFields::text($data['reason'] ?? null), $data['place_code'] ?? null, $corrections, $choices);
        if ($request->expectsJson()) {
            return response()->json(['data' => []]);
        }

        return back()->with('success', __('portal.flash_application_'.$data['decision']));
    }

    public function order(Request $request, TerritoryAccess $territories, OrderService $orders): Response
    {
        // Staff orders follow the partner rules, including "Full payment required when ordering".
        $orderRules = new StoreOrderRequest;
        TransactionReference::tidy($request, 'reference');
        $data = $request->validate([...$orderRules->rules(), 'user_id' => 'required|integer|exists:users,id'], $orderRules->messages());
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
        $claims = DB::table('payment_claims')->where('order_id', $order->id)->get(['id', 'channel', 'reference', 'amount', 'status', 'reason', 'created_at']);
        $data = ['order' => $safeOrder, 'backUrl' => '/operations/field-sales',
            'outstanding' => $order->outstanding(),
            'paymentPrompt' => app(OrderPaymentPrompt::class)->for($order, $claims),
            'claims' => $claims,
            'events' => DB::table('order_events')->where('order_id', $order->id)->orderBy('id')->get(['id', 'action', 'metadata', 'created_at']),
            'shipments' => DB::table('shipments')->where('order_id', $order->id)->orderBy('id')->get(['id', 'courier', 'tracking', 'courier_contact', 'eta', 'status', 'courier_status', 'lines', 'delivered_at', 'created_at'])];

        return $request->expectsJson() ? response()->json(['data' => $data]) : Inertia::render('OrderDetail', $data);
    }

    /** @param array<string, mixed> $data */
    private function done(Request $request, array $data = [], string $message = 'portal.flash_sales_operation'): Response
    {
        return $request->expectsJson()
            ? response()->json(['data' => $data])
            : back()->with('success', __($message));
    }
}
