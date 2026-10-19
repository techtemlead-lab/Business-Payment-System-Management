<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\DueAdjustments;
use App\Domain\Orders\OrderService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\ClaimPaymentRequest;
use App\Http\Requests\Backend\ConfirmDeliveryRequest;
use App\Http\Requests\Backend\DecideOrderRequest;
use App\Http\Requests\Backend\DispatchOrderRequest;
use App\Http\Requests\Backend\StoreOrderRequest;
use App\Http\Requests\Backend\VerifyPaymentRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $companyAccess = $user->allows('orders') || $user->allows('finance');
        abort_unless($companyAccess || in_array($user->role, ['dealer', 'sub_dealer'], true), 403);
        $data = $request->validate([
            'status' => 'sometimes|in:submitted,held,approved,rejected,cancelled,partially_dispatched,dispatched,delivered',
            'q' => 'nullable|string|max:100', 'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
        ]);
        $query = Order::query()->when(! $companyAccess, fn ($q) => $q->where('user_id', $user->id))
            ->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(! empty($data['q']), fn ($q) => $q->where('number', 'like', '%'.$data['q'].'%'));

        return OrderResource::collection($query->with('lines')->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 12)->withQueryString());
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        $data = (new OrderResource($order->load('lines')))->resolve($request);
        $data['due_adjustments'] = app(DueAdjustments::class)->history($order);
        $data['payment_claims'] = DB::table('payment_claims')->where('order_id', $order->id)->orderBy('id')
            ->get(['id', 'channel', 'reference', 'amount', 'status', 'reason', 'created_at']);
        $data['shipments'] = DB::table('shipments')->where('order_id', $order->id)->orderBy('id')
            ->get(['id', 'courier', 'tracking', 'eta', 'status', 'lines', 'delivered_at', 'created_at'])
            ->map(function ($shipment): object {
                $shipment->lines = json_decode($shipment->lines, true, flags: JSON_THROW_ON_ERROR);

                return $shipment;
            });

        return response()->json(['data' => $data]);
    }

    public function store(StoreOrderRequest $request, OrderService $service): OrderResource
    {
        $order = $service->create($request->user(), $request->validated());

        return new OrderResource($order->load('lines'));
    }

    public function decision(DecideOrderRequest $request, Order $order, OrderService $service): OrderResource
    {
        $data = $request->validated();
        $service->decision($request->user(), $order, $data['status'], $data['reason'], $request->boolean('allow_credit'), $request->replacements());

        return new OrderResource($order->refresh()->load('lines'));
    }

    public function dispatch(DispatchOrderRequest $request, Order $order, OrderService $service): OrderResource
    {
        $service->dispatch($request->user(), $order, $request->validated());

        return new OrderResource($order->refresh()->load('lines'));
    }

    public function claim(ClaimPaymentRequest $request, Order $order, OrderService $service): JsonResponse
    {
        $service->claim($request->user(), $order, $request->validated());

        return response()->json(['data' => ['message' => 'Payment reference submitted for verification.']], 201);
    }

    public function verify(VerifyPaymentRequest $request, int $claim, OrderService $service): JsonResponse
    {
        $data = $request->validated();
        $service->verify($request->user(), $claim, $request->boolean('approve'), $data['reason']);

        return response()->json(['data' => ['message' => 'Payment review recorded.']]);
    }

    public function delivered(ConfirmDeliveryRequest $request, Order $order, int $shipment, OrderService $service): OrderResource
    {
        $service->deliver($request->user(), $order, $shipment, $request->validated('reason'));

        return new OrderResource($order->refresh()->load('lines'));
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        $user = $request->user();
        abort_unless($order->user_id === $user->id || $user->allows('orders') || $user->allows('finance'), 404);
    }
}
