<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LedgerController extends WorkspaceController
{
    public function ledger(Request $r, LedgerService $ledger): Response
    {
        $q = LedgerEntry::query();
        $dealer = $r->user()->dealer;
        if ($r->user()->role === 'dealer') {
            $q->where('dealer_id', $dealer->id);
        } else {
            $this->admin($r);
        }

        return Inertia::render('Ledger', ['entries' => $q->latest('id')->paginate(20), 'balances' => $dealer ? $ledger->balances($dealer->id) : ['earned_minor' => (int) LedgerEntry::whereIn('kind', ['earned', 'reversal'])->sum('amount_minor'), 'reserved_minor' => -(int) LedgerEntry::whereIn('kind', ['reserved', 'released'])->sum('amount_minor'), 'paid_minor' => -(int) LedgerEntry::where('kind', 'paid')->sum('amount_minor')]]);
    }
}
