<?php

namespace App\Http\Controllers;

use App\Domain\Orders\OrderReturns;
use App\Domain\Orders\OrderWatchers;
use App\Models\Order;
use App\Support\ReasonFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cancel, return and refund requests on an order: the partner asks or takes the request back;
 * the desk starts one, approves, confirms as the second person, or turns it down.
 * {@see OrderReturns} checks every right again.
 */
class OrderReturnController extends Controller
{
    /** The partner asks to cancel (before shipping) or return (after delivery). */
    public function ask(Request $request, Order $order, OrderReturns $returns): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in([OrderReturns::KindCancel, OrderReturns::KindReturn])],
            'lines' => ['array', 'max:200'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:10000000'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $result = $returns->ask($request->user(), $order, $data['kind'], $data['lines'] ?? [], trim($data['reason']));

        return back(fallback: '/orders/'.$order->id)->with('success', __($result['cancelled_now'] ? 'orders_stock.flash_cancelled_now' : 'orders_stock.flash_asked'));
    }

    /** The partner says the parcel reached him (OS-11); the store still confirms the delivery. */
    public function received(Request $request, Order $order, OrderWatchers $watchers): RedirectResponse
    {
        $watchers->received($request->user(), $order);

        return back(fallback: '/orders/'.$order->id)->with('success', __('orders_stock.flash_received'));
    }

    public function withdraw(Request $request, int $returnRequest, OrderReturns $returns): RedirectResponse
    {
        $returns->withdraw($request->user(), $returnRequest);

        return back()->with('success', __('orders_stock.flash_withdrawn'));
    }

    /** The desk starts a cancel, return or refund and approves it in one step. */
    public function start(Request $request, Order $order, OrderReturns $returns): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(OrderReturns::Kinds)],
            'lines' => ['array', 'max:200'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:10000000'],
            ...$this->approvalRules(),
        ]);
        $result = $returns->start($request->user(), $order, $data['kind'], $data['lines'] ?? [], ReasonFields::text($data['reason'] ?? null),
            (string) ($data['refund_amount'] ?? ''), $data['refund_method'], isset($data['branch_id']) ? (int) $data['branch_id'] : null);

        return back(fallback: '/orders/'.$order->id)->with('success', __($result['done'] ? 'orders_stock.flash_done' : 'orders_stock.flash_waiting_second'));
    }

    public function approve(Request $request, int $returnRequest, OrderReturns $returns): RedirectResponse
    {
        $data = $request->validate($this->approvalRules());
        $done = $returns->approve($request->user(), $returnRequest, (string) ($data['refund_amount'] ?? ''), $data['refund_method'],
            isset($data['branch_id']) ? (int) $data['branch_id'] : null, ReasonFields::text($data['reason'] ?? null));

        return back()->with('success', __($done ? 'orders_stock.flash_done' : 'orders_stock.flash_waiting_second'));
    }

    public function confirm(Request $request, int $returnRequest, OrderReturns $returns): RedirectResponse
    {
        $data = $request->validate(['reason' => ReasonFields::rule('order.refund', 1000), 'confirmed' => ['accepted']]);
        $returns->confirm($request->user(), $returnRequest, ReasonFields::text($data['reason'] ?? null));

        return back()->with('success', __('orders_stock.flash_done'));
    }

    public function reject(Request $request, int $returnRequest, OrderReturns $returns): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $returns->reject($request->user(), $returnRequest, trim($data['reason']));

        return back()->with('success', __('orders_stock.flash_rejected'));
    }

    /** @return array<string, mixed> */
    private function approvalRules(): array
    {
        return [
            // Typed by the person, never filled in for them; the page also asks them to confirm the amount.
            'refund_amount' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/D'],
            'refund_method' => ['required', Rule::in([OrderReturns::MethodAccount, OrderReturns::MethodCash])],
            'branch_id' => ['nullable', 'integer', 'exists:company_branches,id'],
            'reason' => ReasonFields::rule('order.refund', 1000),
            'confirmed' => ['accepted'],
        ];
    }
}
