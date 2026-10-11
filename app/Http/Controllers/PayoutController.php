<?php

namespace App\Http\Controllers;

use App\Models\Payout;
use App\Models\WithdrawalRequest;
use App\Services\FinancialCalculator;
use App\Services\LedgerService;
use App\Services\PayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayoutController extends WorkspaceController
{
    public function payouts(Request $r, LedgerService $ledger): Response
    {
        $q = Payout::query();
        if ($r->user()->role === 'dealer') {
            $q->where('dealer_id', $r->user()->dealer->id);
        } else {
            $this->admin($r);
        }

        $withdrawals = WithdrawalRequest::query();
        if ($r->user()->role === 'dealer') {
            $withdrawals->where('dealer_id', $r->user()->dealer->id);
        }

        return Inertia::render('Payouts', [
            'payouts' => $q->latest('id')->paginate(20, ['*'], 'payout_page'),
            'withdrawals' => $withdrawals->with('dealer:id,user_id,code', 'dealer.user:id,name')->latest('id')->paginate(12)->through(fn ($item) => [...$item->only(['id', 'dealer_id', 'amount_minor', 'status', 'note', 'review_note', 'created_at']), 'dealer_name' => $item->dealer->user->name, 'dealer_code' => $item->dealer->code]),
            'balances' => $r->user()->role === 'dealer' ? $ledger->balances($r->user()->dealer->id) : null,
        ]);
    }

    public function requestPayout(Request $r, FinancialCalculator $c, PayoutService $p): RedirectResponse
    {
        abort_unless($r->user()->role === 'dealer', 403);
        $r->validate(['amount' => 'required|string', 'idempotency_key' => 'required|uuid']);
        $p->request($r->user()->dealer->id, $c->parseMoney($r->amount), $r->idempotency_key);

        return back();
    }
}
