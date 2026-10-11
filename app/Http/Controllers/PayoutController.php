<?php

namespace App\Http\Controllers;

use App\Models\Payout;
use App\Services\FinancialCalculator;
use App\Services\PayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayoutController extends WorkspaceController
{
    public function payouts(Request $r): Response
    {
        $q = Payout::query();
        if ($r->user()->role === 'dealer') {
            $q->where('dealer_id', $r->user()->dealer->id);
        } else {
            $this->admin($r);
        }

        return Inertia::render('Payouts', ['payouts' => $q->latest('id')->paginate(20)]);
    }

    public function requestPayout(Request $r, FinancialCalculator $c, PayoutService $p): RedirectResponse
    {
        abort_unless($r->user()->role === 'dealer', 403);
        $r->validate(['amount' => 'required|string', 'idempotency_key' => 'required|uuid']);
        $p->request($r->user()->dealer->id, $c->parseMoney($r->amount), $r->idempotency_key);

        return back();
    }
}
