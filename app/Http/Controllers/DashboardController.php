<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Dealer;
use App\Models\DealershipRequest;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\Purchase;
use App\Models\RankDefinition;
use App\Models\WithdrawalRequest;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends WorkspaceController
{
    public function dashboard(Request $r, LedgerService $ledger): Response
    {
        if ($r->user()->role === 'dealer') {
            return $this->portal($r, $ledger);
        }
        $this->admin($r);
        $earned = (int) LedgerEntry::whereIn('kind', ['earned', 'reversal'])->sum('amount_minor');

        $dealerStats = Dealer::query()->selectRaw('COUNT(*) AS dealers, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS active, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending', ['active', 'pending'])->first();
        $levelCounts = Dealer::query()->selectRaw('level, COUNT(*) AS total')->groupBy('level')->pluck('total', 'level');

        return Inertia::render('Dashboard', ['stats' => ['dealers' => (int) $dealerStats->dealers, 'active' => (int) $dealerStats->active, 'pending' => (int) $dealerStats->pending, 'earned_minor' => $earned, 'payouts' => Payout::whereIn('status', ['pending', 'processing', 'needs_reconciliation'])->count() + WithdrawalRequest::whereIn('status', ['pending', 'approved'])->count()], 'dealers' => $this->dealerRows(Dealer::query()->limit(5)), 'levels' => collect(['Divisional', 'District', 'Upazila / Thana', 'Union'])->map(fn ($level) => ['name' => $level, 'count' => (int) ($levelCounts[$level] ?? 0)]), 'activities' => AuditEvent::latest('id')->limit(4)->get(), 'preview' => null, 'purchaseTotals' => Purchase::allocationTotals(), 'requestCount' => DealershipRequest::where('status', 'pending')->count(), 'requests' => DealershipRequest::where('status', 'pending')->latest('id')->limit(3)->get(['id', 'name', 'business_name', 'level', 'division', 'created_at']), 'rankReady' => RankDefinition::where('status', 'approved')->exists()]);
    }

    private function portal(Request $r, LedgerService $ledger): Response
    {
        $d = $r->user()->dealer;

        return Inertia::render('Portal', ['dealer' => $this->dealerRows(Dealer::whereKey($d->id))->first(), 'balances' => $ledger->balances($d->id), 'entries' => LedgerEntry::where('dealer_id', $d->id)->latest('id')->limit(5)->get()]);
    }
}
