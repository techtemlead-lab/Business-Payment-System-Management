<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\BusinessConfig;
use App\Models\Dealer;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\RuleVersion;
use App\Services\FinancialCalculator;
use App\Services\LedgerService;
use App\Services\ReferralService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController extends WorkspaceController
{
    public function index(Request $request): Response
    {
        $query = Purchase::query();
        if ($request->user()->role === 'dealer') {
            $query->where('dealer_id', $request->user()->dealer->id);
        } else {
            $this->admin($request);
        }
        $request->validate(['q' => 'nullable|string|max:80']);
        if ($request->filled('q')) {
            $term = '%'.$request->input('q').'%';
            $query->where(fn ($builder) => $builder->whereLike('reference', $term)->orWhereLike('product_name', $term));
        }
        $totals = (clone $query)->where('status', 'recorded')->selectRaw('COUNT(*) AS records, SUM(total_mrp_minor) AS mrp, SUM(amount_paid_minor) AS paid')->first();

        return Inertia::render('Purchases', [
            'purchases' => $query->with('dealer:id,user_id,code', 'dealer.user:id,name')->latest('id')->paginate(15)->withQueryString()->through(fn ($purchase) => [...$purchase->only(['id', 'reference', 'product_name', 'quantity', 'unit_mrp_minor', 'total_mrp_minor', 'amount_paid_minor', 'purchased_on', 'status', 'calculation', 'note', 'cancellation_reason', 'ledger_entry_id']), 'dealer_name' => $purchase->dealer->user->name, 'dealer_code' => $purchase->dealer->code]),
            'filters' => ['q' => $request->input('q', '')],
            'totals' => ['records' => (int) $totals->records, 'mrp_minor' => (int) $totals->mrp, 'paid_minor' => (int) $totals->paid],
        ]);
    }

    public function store(Request $request, FinancialCalculator $calculator, ReferralService $referrals): RedirectResponse
    {
        $this->admin($request);
        $this->stepUp($request);
        $validated = $request->validate(['dealer_code' => 'required|string|max:30|exists:dealers,code', 'reference' => 'required|string|max:80', 'product_name' => 'required|string|max:160', 'quantity' => 'required|integer|min:1|max:100000', 'unit_mrp' => 'required|string|max:12', 'amount_paid' => 'required|string|max:12', 'purchased_on' => 'required|date|before_or_equal:today', 'note' => 'nullable|string|max:500']);
        $amounts = [];
        foreach (['unit_mrp', 'amount_paid'] as $field) {
            try {
                $amounts[$field] = $calculator->parseMoney($validated[$field]);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages([$field => 'Enter a valid BDT amount with up to two decimal places.']);
            }
        }
        $total = $amounts['unit_mrp'] * (int) $validated['quantity'];
        if ($total <= 0 || $total > 99999999999) {
            throw ValidationException::withMessages(['unit_mrp' => 'Total MRP must be positive and at most BDT 999,999,999.99.']);
        }
        DB::transaction(function () use ($request, $validated, $amounts, $total, $calculator, $referrals): void {
            $config = BusinessConfig::query()->lockForUpdate()->findOrFail(1);
            $dealer = Dealer::where('code', $validated['dealer_code'])->lockForUpdate()->firstOrFail();
            if ($dealer->status !== 'active') {
                throw ValidationException::withMessages(['dealer_code' => 'Purchases require an active dealer account.']);
            }
            $existing = Purchase::where('reference', $validated['reference'])->first();
            if ($existing) {
                if ($existing->dealer_id !== $dealer->id || $existing->product_name !== $validated['product_name'] || $existing->quantity !== (int) $validated['quantity'] || $existing->unit_mrp_minor !== $amounts['unit_mrp'] || $existing->amount_paid_minor !== $amounts['amount_paid'] || $existing->purchased_on->toDateString() !== $validated['purchased_on']) {
                    throw ValidationException::withMessages(['reference' => 'This reference already belongs to a different purchase.']);
                }

                return;
            }
            $version = $config->active_rule_version_id ? RuleVersion::findOrFail($config->active_rule_version_id) : RuleVersion::firstOrCreate(['name' => 'Confirmed dealer-purchase model', 'status' => 'reference'], ['definition' => FinancialCalculator::definition(), 'reason' => 'Company-confirmed purchase workflow: total product MRP; 50% credited to the purchasing dealer, 30% company share, 20% reward pool. Pool recipient eligibility remains undefined.']);
            if (($version->effective_from && $validated['purchased_on'] < $version->effective_from) || ($version->effective_to && $validated['purchased_on'] > $version->effective_to)) {
                throw ValidationException::withMessages(['purchased_on' => 'The purchase date falls outside the active rule version’s effective date range.']);
            }
            $calculation = $calculator->preview($total, $version->definition);
            $calculation['preview_only'] = $config->mode !== 'COMMISSION_BASED';
            $calculation['referral_snapshot'] = $referrals->ancestors($dealer);
            $purchase = Purchase::create(['dealer_id' => $dealer->id, 'reference' => $validated['reference'], 'product_name' => $validated['product_name'], 'quantity' => (int) $validated['quantity'], 'unit_mrp_minor' => $amounts['unit_mrp'], 'total_mrp_minor' => $total, 'amount_paid_minor' => $amounts['amount_paid'], 'purchased_on' => $validated['purchased_on'], 'calculation' => $calculation, 'company_minor' => $calculation['company_minor'], 'dealer_minor' => $calculation['dealer_minor'], 'pool_minor' => $calculation['pool_minor'], 'allocated_pool_minor' => $calculation['allocated_minor'], 'unallocated_pool_minor' => $calculation['unallocated_minor'], 'rule_version_id' => $version->id, 'note' => $validated['note'] ?? null, 'actor_id' => $request->user()->id, 'status' => 'recorded']);
            $purchase->update(['referral_snapshot' => $calculation['referral_snapshot']]);
            if ($config->mode === 'COMMISSION_BASED' && $calculation['dealer_minor'] > 0) {
                $earning = LedgerEntry::create(['dealer_id' => $dealer->id, 'amount_minor' => $calculation['dealer_minor'], 'kind' => 'earned', 'earning_category' => 'purchase_commission', 'business_reference' => 'purchase-earning-'.$purchase->id, 'rule_version_id' => $version->id, 'actor_id' => $request->user()->id, 'reason' => ($calculation['dealer_bps'] / 100).'% of total product MRP under rule V'.$version->id.' for purchase '.$purchase->reference, 'created_at' => now()]);
                $purchase->update(['ledger_entry_id' => $earning->id]);
            }
            AuditEvent::record('purchase.recorded', ['purchase_id' => $purchase->id, 'dealer_id' => $dealer->id, 'total_mrp_minor' => $total, 'amount_paid_minor' => $purchase->amount_paid_minor, 'preview_only' => $calculation['preview_only']], $dealer->id);
        });

        return back()->with('success', 'Purchase recorded using the active commission rule. In commission mode, the dealer share is credited once. Referral and reward payouts remain pending their business rules.');
    }

    public function cancel(Request $request, Purchase $purchase, LedgerService $ledger): RedirectResponse
    {
        $this->admin($request);
        $this->stepUp($request);
        $validated = $request->validate(['reason' => 'required|string|min:5|max:500']);
        DB::transaction(function () use ($request, $purchase, $validated, $ledger): void {
            BusinessConfig::query()->lockForUpdate()->findOrFail(1);
            $record = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($record->status !== 'recorded') {
                throw ValidationException::withMessages(['purchase' => 'This purchase is already cancelled.']);
            }
            if ($record->ledger_entry_id) {
                $ledger->reverse($record->ledger_entry_id, 'purchase-reversal-'.$record->id, $validated['reason']);
            }
            $record->update(['status' => 'cancelled', 'cancelled_by' => $request->user()->id, 'cancellation_reason' => $validated['reason']]);
            AuditEvent::record('purchase.cancelled', ['purchase_id' => $record->id, 'reason' => $validated['reason']], $record->dealer_id);
        });

        return back()->with('success', 'Purchase cancelled. Its original details remain in the history.');
    }
}
