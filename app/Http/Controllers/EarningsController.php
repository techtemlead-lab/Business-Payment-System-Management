<?php

namespace App\Http\Controllers;

use App\Models\Dealer;
use App\Models\Purchase;
use App\Services\EarningsService;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EarningsController extends WorkspaceController
{
    public function index(Request $request, EarningsService $earnings, LedgerService $ledger): Response
    {
        $isAdmin = $request->user()->role === 'admin';
        if ($isAdmin) {
            $this->admin($request);
        }
        $filters = $request->validate(['category' => ['nullable', Rule::in(array_keys(EarningsService::CATEGORIES))], 'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])]]);
        if ($isAdmin) {
            $filters += $request->validate(['code' => 'nullable|string|max:30|exists:dealers,code']);
        }
        $dealer = $isAdmin ? (! empty($filters['code']) ? Dealer::with('user:id,name')->where('code', $filters['code'])->firstOrFail() : null) : $request->user()->dealer;
        $query = $earnings->query($dealer?->id, $filters['from'] ?? null, $filters['to'] ?? null);
        $categories = $earnings->breakdown($query, $dealer?->isAreaDealer());
        $expression = $earnings->categoryExpression();
        if (! empty($filters['category'])) {
            $query->whereRaw($expression.' = ?', [$filters['category']]);
        }
        $entries = $query->join('dealers', 'ledger_entries.dealer_id', '=', 'dealers.id')->join('users', 'dealers.user_id', '=', 'users.id')
            ->select('ledger_entries.id', 'ledger_entries.amount_minor', 'ledger_entries.kind', 'ledger_entries.business_reference', 'ledger_entries.created_at', 'ledger_entries.reason', 'ledger_entries.rule_version_id', 'ledger_entries.reversal_of', 'dealers.code as dealer_code', 'users.name as dealer_name')
            ->selectRaw($expression.' AS category')->orderByDesc('ledger_entries.id')->paginate(20)->withQueryString();
        $companyQuery = Purchase::where('status', 'recorded')->where('calculation->preview_only', false)
            ->when($dealer, fn ($query) => $query->where('dealer_id', $dealer->id))
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('purchased_on', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('purchased_on', '<=', $date));

        return Inertia::render('Earnings', [
            'categories' => $categories, 'entries' => $entries,
            'filters' => [...$filters, 'code' => $isAdmin ? ($filters['code'] ?? '') : ''],
            'dealer' => $dealer ? ['code' => $dealer->code, 'name' => $dealer->user->name, 'dealer_type' => $dealer->dealer_type] : null,
            'balances' => $dealer ? $ledger->balances($dealer->id) : null,
            'companyShareMinor' => $isAdmin ? (int) $companyQuery->sum('company_minor') : null,
        ]);
    }
}
