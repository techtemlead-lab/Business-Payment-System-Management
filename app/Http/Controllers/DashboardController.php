<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Dealer;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\RankDefinition;
use App\Services\FinancialCalculator;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends WorkspaceController
{
    public function dashboard(Request $r, LedgerService $ledger, FinancialCalculator $calculator): Response
    {
        if ($r->user()->role === 'dealer') {
            return $this->portal($r, $ledger);
        }
        $this->admin($r);
        $earned = (int) LedgerEntry::whereIn('kind', ['earned', 'reversal'])->sum('amount_minor');

        return Inertia::render('Dashboard', ['stats' => ['dealers' => Dealer::count(), 'active' => Dealer::where('status', 'active')->count(), 'pending' => Dealer::where('status', 'pending')->count(), 'earned_minor' => $earned, 'payouts' => Payout::whereIn('status', ['pending', 'processing', 'needs_reconciliation'])->count()], 'dealers' => $this->dealerRows(Dealer::query()->limit(5)), 'levels' => collect(['Divisional', 'District', 'Upazila / Thana', 'Union'])->map(fn ($level) => ['name' => $level, 'count' => Dealer::where('level', $level)->count()]), 'activities' => AuditEvent::latest('id')->limit(4)->get(), 'preview' => $calculator->preview(20000), 'rankReady' => RankDefinition::where('status', 'approved')->exists()]);
    }

    private function portal(Request $r, LedgerService $ledger): Response
    {
        $d = $r->user()->dealer;

        return Inertia::render('Portal', ['dealer' => $this->dealerRows(Dealer::whereKey($d->id))->first(), 'balances' => $ledger->balances($d->id), 'entries' => LedgerEntry::where('dealer_id', $d->id)->latest('id')->limit(5)->get()]);
    }
}
