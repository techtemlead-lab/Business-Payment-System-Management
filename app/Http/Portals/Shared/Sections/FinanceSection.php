<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Commissions\RetainedFundLedger;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Geography\ManagementReferral;
use App\Domain\Orders\OrderListFilter;
use App\Models\CommissionAllocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Company money: payment claims, the journal ledger, the audit log, treasury and the benefits fund.
 */
final class FinanceSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        return match ($context->section) {
            'payments' => $this->paymentClaims($context->request),
            'ledger' => $this->ledger(),
            'audit' => $this->auditLog(),
            'capital-flow', 'treasury' => $this->treasury($context),
            'benefits-fund' => $this->benefitsFund(),
            default => [],
        };
    }

    /**
     * Payment claims with their order and buyer, filtered like the payment queue.
     *
     * @return array<string, mixed>
     */
    private function paymentClaims(Request $request): array
    {
        $props = [];
        $claims = DB::table('payment_claims')
            ->join('orders', 'orders.id', '=', 'payment_claims.order_id')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->leftJoin('company_branches', 'company_branches.id', '=', 'orders.fulfillment_branch_id')
            ->leftJoin('users as checkers', 'checkers.id', '=', 'payment_claims.verified_by')
            ->select(
                'checkers.name as checked_by',
                'payment_claims.updated_at as checked_at',
                'payment_claims.id',
                'payment_claims.order_id',
                'payment_claims.channel',
                'payment_claims.reference',
                'payment_claims.amount',
                'payment_claims.status',
                'payment_claims.reason',
                'payment_claims.created_at',
                DB::raw('(payment_claims.proof_path IS NOT NULL) AS has_proof'),
                'orders.number',
                'orders.total',
                'orders.paid',
                'orders.due_credit',
                'orders.status as order_status',
                'users.name',
                'users.phone',
                'users.dealer_code',
                'users.role',
                'users.district',
                'users.division',
                'company_branches.name as branch_name',
            );
        if ($request->filled('q')) {
            $term = '%'.$request->string('q')->toString().'%';
            $claims->where(function ($query) use ($term): void {
                $query->where('orders.number', 'ilike', $term)
                    ->orWhere('users.name', 'ilike', $term)
                    ->orWhere('users.phone', 'ilike', $term)
                    ->orWhere('users.dealer_code', 'ilike', $term)
                    ->orWhere('payment_claims.reference', 'ilike', $term);
            });
        }
        if ($request->filled('buyer_role')) {
            $claims->where('users.role', $request->string('buyer_role')->toString());
        }
        $channel = $request->string('channel')->toString();
        if (in_array($channel, ['Bank', 'bKash', 'Nagad', 'Rocket'], true)) {
            $claims->where('payment_claims.channel', $channel);
        }
        $range = OrderListFilter::createdRange(
            $request->string('period')->toString(),
            $request->filled('from') ? $request->string('from')->toString() : null,
            $request->filled('to') ? $request->string('to')->toString() : null,
        );
        if ($range !== null) {
            $claims->where('payment_claims.created_at', '>=', $range['from'])
                ->where('payment_claims.created_at', '<=', $range['until']);
        }
        // Counts for the status tabs: every filter on screen except status itself.
        $props['claim_status_counts'] = (clone $claims)
            ->select('payment_claims.status')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('payment_claims.status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
        $claimStatus = $request->string('status')->toString();
        if (in_array($claimStatus, ['pending', 'verified', 'rejected'], true)) {
            $claims->where('payment_claims.status', $claimStatus);
        }
        $props['claims'] = $claims->orderByDesc('payment_claims.id')->paginate(20)->withQueryString();

        return $props;
    }

    /**
     * Journal entries with their debit and credit totals.
     *
     * @return array<string, mixed>
     */
    private function ledger(): array
    {
        return [
            'entries' => DB::table('journal_entries')
                ->leftJoin('users', 'users.id', '=', 'journal_entries.actor_id')
                ->select('journal_entries.id', 'journal_entries.event_key', 'journal_entries.description', 'journal_entries.created_at', 'users.name as actor_name')
                ->selectSub(fn ($query) => $query->from('journal_lines')->whereColumn('journal_lines.entry_id', 'journal_entries.id')->selectRaw('coalesce(sum(debit), 0)'), 'debit')
                ->selectSub(fn ($query) => $query->from('journal_lines')->whereColumn('journal_lines.entry_id', 'journal_entries.id')->selectRaw('coalesce(sum(credit), 0)'), 'credit')
                ->orderByDesc('journal_entries.created_at')
                ->paginate(20),
        ];
    }

    /**
     * Audit events, newest first.
     *
     * @return array<string, mixed>
     */
    private function auditLog(): array
    {
        return [
            'events' => DB::table('audit_events')->leftJoin('users', 'users.id', '=', 'actor_id')->select('audit_events.id', 'action', 'subject', 'audit_events.created_at', 'users.name')->orderByDesc('audit_events.id')->paginate(25),
        ];
    }

    /**
     * Where partner cash went: company share, commission pools, reserves and payouts.
     *
     * @return array<string, mixed>
     */
    private function treasury(SectionContext $context): array
    {
        $user = $context->user;
        $props = [];
        $collected = DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->leftJoinSub(
                DB::table('receipts')->selectRaw('order_id, SUM(amount) AS collected')->groupBy('order_id'),
                'receipt_totals',
                'receipt_totals.order_id',
                '=',
                'orders.id',
            )
            ->whereIn('users.role', ['dealer', 'sub_dealer'])
            ->where('orders.is_opening_balance', false)
            ->groupBy('users.role')
            ->selectRaw('users.role AS role, COALESCE(SUM(GREATEST(orders.paid, COALESCE(receipt_totals.collected, 0))), 0) AS amount')
            ->pluck('amount', 'role');
        $dealerCash = (float) ($collected['dealer'] ?? 0);
        $subDealerCash = (float) ($collected['sub_dealer'] ?? 0);
        $cash = $dealerCash + $subDealerCash;
        // Leftovers of a settled salary or rank pool are saved as new rows (company profit or a
        // reserve) while the pool row keeps its full amount, so counting both would count that
        // money twice. Only original rows count toward what the commission pool handed out.
        $original = fn ($query) => $query->whereRaw("(metadata->>'source_allocation_id') IS NULL");
        $allocated = CommissionAllocation::where('status', '!=', 'reversed')
            ->where('category', '!=', 'company_commission_benefits_fund')
            ->where($original)
            ->sum('amount');
        $unclaimedToCompany = CommissionAllocation::where('status', '!=', 'reversed')
            ->where('category', CommissionSettlement::CompanyProfitCategory)
            ->sum('amount');
        $company = DB::table('commission_batches')->selectRaw('COALESCE(SUM(company_share * (cash_basis - refunded_amount) / cash_basis), 0) AS amount')->value('amount');
        $buffer = DB::table('commission_batches')->selectRaw('COALESCE(SUM(unallocated * (cash_basis - refunded_amount) / cash_basis), 0) AS amount')->value('amount');
        $management = app(ManagementReferral::class)->fundAmount();
        $unassigned = CommissionAllocation::where('status', 'unassigned_reserve')->sum('amount');
        $props['flow'] = [
            'gross_cash_inflow' => $cash,
            'dealer_cash_inflow' => $dealerCash,
            'sub_dealer_cash_inflow' => $subDealerCash,
            'channel_breakdown' => DB::table('receipts')->selectRaw('channel, SUM(amount) AS total_amount, COUNT(*) AS count')->groupBy('channel')->get(),
            'company_share_60' => round((float) $company, 2),
            'management_reserve_2' => (float) $management,
            'unassigned_territory_reserve' => (float) $unassigned,
            'total_company_deposits' => round((float) $company + (float) $management + (float) $unassigned, 2),
            'commission_pool_40' => (float) $allocated,
            'allocated_so_far' => (float) $allocated,
            'settled_disbursements' => (float) DB::table('withdrawals')->where('status', 'paid')->sum(DB::raw('COALESCE(net_amount, amount)')),
            'maturing_commissions' => (float) CommissionAllocation::where('status', 'maturing')->sum('amount'),
            'marketing_fund' => (float) CommissionAllocation::where('category', 'company_marketing_fund')->sum('amount'),
            'car_bike_fund' => (float) CommissionAllocation::where('category', 'car_bike_incentive_fund')->sum('amount'),
            'tds_tax_withheld' => (float) DB::table('withdrawals')->where('status', 'paid')->sum('withheld_amount'),
            'refunded_purchase_value' => (float) DB::table('commission_refunds')->sum('amount'),
            'unclaimed_to_company_profit' => (float) $unclaimedToCompany,
        ];
        $ownerBenefits = $user->owner() && $context->portal === 'sadmin';
        // Owner-only: purchase surplus after company share + commissions.
        if ($ownerBenefits) {
            $props['flow']['unallocated_pool_buffer'] = round((float) $buffer, 2);
            $props['flow']['commission_benefits_fund'] = round((float) $buffer, 2);
            $props['flow']['owner_benefits_fund_visible'] = true;
        }
        $props['allocations_by_category'] = CommissionAllocation::where('status', '!=', 'reversed')
            ->where($original)
            ->when(! $ownerBenefits, fn ($q) => $q->where('category', '!=', 'company_commission_benefits_fund'))
            ->selectRaw('category, SUM(amount) AS total_amount, COUNT(*) AS count')->groupBy('category')->get();
        $props['recent_allocations'] = CommissionAllocation::with('user:id,name,dealer_code')
            ->when(! $ownerBenefits, fn ($q) => $q->where('category', '!=', 'company_commission_benefits_fund'))
            ->latest()->limit(20)->get();
        $props['retained_fund_details'] = app(RetainedFundLedger::class)->detail($ownerBenefits);
        $props['payment_accounts'] = $user->allows('finance')
            ? app(PaymentAccounts::class)->listing($user, false, false)
            : [];

        return $props;
    }

    /**
     * The retained commission benefits fund and its entries.
     *
     * @return array<string, mixed>
     */
    private function benefitsFund(): array
    {
        $props = [];
        $ledger = app(RetainedFundLedger::class);
        $balance = DB::table('commission_batches')
            ->selectRaw('COALESCE(SUM(unallocated * (cash_basis - refunded_amount) / cash_basis), 0) AS amount')
            ->value('amount');
        $entries = CommissionAllocation::query()
            ->where('category', RetainedFundLedger::BenefitsCategory)
            ->with(['order:id,number,user_id,total,commission_policy_snapshot', 'order.user:id,name,dealer_code,division,district,role,profile'])
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (CommissionAllocation $allocation): array => $ledger->benefitsEntry($allocation));
        $props['benefits_fund'] = [
            'balance' => round((float) $balance, 2),
            'entry_count' => CommissionAllocation::where('category', RetainedFundLedger::BenefitsCategory)->where('status', '!=', 'reversed')->count(),
            'description' => __('portal.benefits_fund_desc_full'),
        ];
        $props['benefits_entries'] = $entries;

        return $props;
    }
}
