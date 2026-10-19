<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionService;
use App\Http\Controllers\Controller;
use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CommissionController extends Controller
{
    public function policy(Request $request, CommissionPolicy $policy): JsonResponse
    {
        abort_unless($request->user()->allows('finance'), 403);

        return response()->json(['data' => $policy->snapshot(), 'financial_activation' => (bool) config('madina.financial_activation')]);
    }

    public function updatePolicy(Request $request, CommissionPolicy $policy): JsonResponse
    {
        abort_unless($request->user()->allows('finance'), 403);
        $data = $request->validate(['rules' => 'required|array']);
        $policy->update($request->user(), $data['rules']);

        return response()->json(['data' => $policy->snapshot()]);
    }

    public function postOrder(Request $request, Order $order, CommissionService $service): JsonResponse
    {
        abort_unless($request->user()->allows('finance'), 403);
        if (! config('madina.financial_activation')) {
            return response()->json([
                'data' => [],
                'posted' => false,
                'reason' => 'Financial posting is disabled until activation after approved policy and opening verification.',
            ], 409);
        }

        return response()->json(['data' => $service->processOrder($order, $request->user()->id), 'posted' => true]);
    }

    public function refund(Request $request, Order $order, CommissionService $service): JsonResponse
    {
        abort_unless($request->user()->allows('finance'), 403);
        $data = $request->validate(['refund_amount' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'reference' => 'required|string|max:100', 'reason' => 'required|string|min:5|max:1000']);

        return response()->json(['data' => $service->reverseOrderPartial($order, $data['refund_amount'], $data['reason'], $data['reference'], $request->user()->id), 'records_commission_adjustment_only' => true]);
    }

    public function settle(Request $request, CommissionService $service): JsonResponse
    {
        abort_unless($request->user()->allows('finance'), 403);
        $data = $request->validate([
            'period_month' => 'required|date_format:Y-m',
            'kind' => ['required', Rule::in(['salary', 'royalty'])],
            'request_key' => 'required|uuid',
        ]);

        return response()->json(['data' => $data['kind'] === 'salary'
            ? $service->settleMonthlySalaryPool($data['period_month'], $request->user()->id, $data['request_key'])
            : $service->settleRankRoyaltyPool($data['period_month'], $request->user()->id, $data['request_key'])]);
    }

    public function earnings(Request $request, CommissionQualification $qualification): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true), 403);

        return response()->json(['data' => [
            'wallet' => DB::table('wallets')->where('user_id', $user->id)->first(['available', 'held', 'commission_held']),
            'monthly_purchase_value' => Money::decimal($qualification->monthlyVolume($user->id, CommissionQualification::currentMonth())),
            'allocations' => CommissionAllocation::where('user_id', $user->id)->latest()->paginate(20),
            'settlements' => DB::table('commission_settlement_payouts')->where('user_id', $user->id)->latest()->limit(30)->get(['kind', 'period_month', 'amount', 'reversed_amount', 'created_at']),
            'financial_activation' => (bool) config('madina.financial_activation'),
        ]]);
    }

    public function network(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'dealer', 403);
        $members = DB::table('referral_tree_paths')->join('users', 'users.id', '=', 'descendant_id')
            ->where('ancestor_id', $request->user()->id)->where('depth', '>', 0)->orderBy('depth')->orderBy('users.id')
            ->paginate(100, ['users.id', 'users.name', 'users.dealer_code', 'users.sponsor_id', 'users.role', 'users.status', 'depth', 'users.created_at']);

        return response()->json($members);
    }

    public function networkOrders(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->role === 'dealer' && ReferralTreePath::isDescendant($request->user()->id, $user->id), 404);

        return response()->json(Order::where('user_id', $user->id)->latest()->paginate(20, ['id', 'number', 'status', 'total', 'paid', 'created_at']));
    }
}
