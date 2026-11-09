<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Commissions\RetainedFundLedger;
use App\Domain\Finance\CompanyFunds;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Geography\ManagementReferral;
use App\Domain\Inventory\BranchScope;
use App\Domain\Orders\OrderListFilter;
use App\Models\CommissionAllocation;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Company money: payment claims, the journal ledger, the audit log, treasury and the benefits fund.
 */
final class FinanceSection implements WorkspaceSection
{
    /** Statuses the benefits-fund list can be filtered by. */
    public const BenefitsStatuses = ['reserved', 'reversed'];

    public function data(SectionContext $context, array $data): array
    {
        return match ($context->section) {
            'payments' => $this->paymentClaims($context->request, $context->branchScope),
            'ledger' => $this->ledger($context->request, $context->user->owner() && $context->portal === 'sadmin'),
            'audit' => $this->auditLog($context->request),
            'capital-flow', 'treasury' => $this->treasury($context),
            'benefits-fund' => $this->benefitsFund($context->request),
            default => [],
        };
    }

    /**
     * Payment claims with their order and buyer, filtered like the payment queue.
     * On a branch picked in the top bar, only claims on that branch's orders.
     *
     * @return array<string, mixed>
     */
    private function paymentClaims(Request $request, ?BranchScope $branchScope = null): array
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
        $branchScope?->restrictOrders($claims);
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
        // Counts for the status tabs and money totals for the whole filtered list (not only the
        // page on screen): every filter except status itself, in one grouped query.
        $groups = (clone $claims)
            ->select('payment_claims.status', 'payment_claims.channel')
            ->selectRaw('COUNT(*) AS aggregate, COALESCE(SUM(payment_claims.amount), 0) AS amount')
            ->groupBy('payment_claims.status', 'payment_claims.channel')
            ->get();
        $props['claim_status_counts'] = $groups->groupBy('status')
            ->map(fn ($rows): int => (int) $rows->sum('aggregate'))
            ->all();
        $props['claim_totals'] = [
            'by_status' => $groups->groupBy('status')->map(fn ($rows): array => [
                'count' => (int) $rows->sum('aggregate'),
                'amount' => Money::decimal($rows->sum(fn ($row): int => Money::cents((string) $row->amount))),
            ])->all(),
            'verified_by_channel' => $groups->where('status', 'verified')->groupBy('channel')->map(fn ($rows): string => Money::decimal(
                $rows->sum(fn ($row): int => Money::cents((string) $row->amount)),
            ))->all(),
        ];
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
    private function ledger(Request $request, bool $showOwnerFunds): array
    {
        $entries = DB::table('journal_entries')->leftJoin('users', 'users.id', '=', 'journal_entries.actor_id')
            ->when(! $showOwnerFunds, fn ($query) => CompanyFunds::withoutOwnerOnlySpends($query, 'journal_entries.event_key'));
        if ($request->filled('q')) {
            $term = '%'.addcslashes($request->string('q')->toString(), '%_\\').'%';
            $entries->where(fn ($query) => $query->where('journal_entries.event_key', 'ilike', $term)
                ->orWhere('journal_entries.description', 'ilike', $term)
                ->orWhere('users.name', 'ilike', $term));
        }
        $this->whereCreatedIn($entries, 'journal_entries.created_at', $request);

        return [
            'entries' => $entries
                ->select('journal_entries.id', 'journal_entries.event_key', 'journal_entries.description', 'journal_entries.created_at', 'users.name as actor_name')
                ->selectSub(fn ($query) => $query->from('journal_lines')->whereColumn('journal_lines.entry_id', 'journal_entries.id')->selectRaw('coalesce(sum(debit), 0)'), 'debit')
                ->selectSub(fn ($query) => $query->from('journal_lines')->whereColumn('journal_lines.entry_id', 'journal_entries.id')->selectRaw('coalesce(sum(credit), 0)'), 'credit')
                ->orderByDesc('journal_entries.created_at')
                ->paginate(20)
                ->withQueryString(),
        ];
    }

    /** Apply the on-screen period (today, this week, a day or custom dates) to a date column. */
    private function whereCreatedIn(Builder $query, string $column, Request $request): void
    {
        $range = OrderListFilter::createdRange(
            $request->string('period')->toString(),
            $request->filled('from') ? $request->string('from')->toString() : null,
            $request->filled('to') ? $request->string('to')->toString() : null,
        );
        if ($range !== null) {
            $query->where($column, '>=', $range['from'])->where($column, '<=', $range['until']);
        }
    }

    /**
     * Audit events, newest first.
     *
     * @return array<string, mixed>
     */
    private function auditLog(Request $request): array
    {
        $events = DB::table('audit_events')->leftJoin('users', 'users.id', '=', 'actor_id');
        if ($request->filled('q')) {
            $term = '%'.addcslashes($request->string('q')->toString(), '%_\\').'%';
            $events->where(fn ($query) => $query->where('audit_events.action', 'ilike', $term)
                ->orWhere('audit_events.subject', 'ilike', $term)
                ->orWhere('users.name', 'ilike', $term));
        }
        $this->whereCreatedIn($events, 'audit_events.created_at', $request);

        return [
            'events' => $events->select('audit_events.id', 'action', 'subject', 'audit_events.created_at', 'users.name')
                ->orderByDesc('audit_events.id')->paginate(25)->withQueryString(),
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
        // Every commission-allocation total in one pass, each with the same filter it had as its own query.
        $pools = DB::table('commission_allocations')->selectRaw(
            "COALESCE(SUM(amount) FILTER (WHERE status <> 'reversed' AND category <> 'company_commission_benefits_fund' AND (metadata->>'source_allocation_id') IS NULL), 0) AS allocated, "
            ."COALESCE(SUM(amount) FILTER (WHERE status <> 'reversed' AND category = ?), 0) AS unclaimed_to_company, "
            ."COALESCE(SUM(amount) FILTER (WHERE status = 'unassigned_reserve'), 0) AS unassigned, "
            ."COALESCE(SUM(amount) FILTER (WHERE status = 'unassigned_reserve' AND (metadata->>'retained_by') IS DISTINCT FROM 'company_profit_fund'), 0) AS old_unassigned, "
            ."COALESCE(SUM(amount) FILTER (WHERE status = 'maturing'), 0) AS maturing, "
            ."COALESCE(SUM(amount) FILTER (WHERE category = 'company_marketing_fund'), 0) AS marketing_fund, "
            ."COALESCE(SUM(amount) FILTER (WHERE category = 'car_bike_incentive_fund'), 0) AS car_bike_fund",
            [CommissionSettlement::CompanyProfitCategory],
        )->first();
        // Batch shares, paid withdrawals and refunds in a second pass.
        $totals = DB::query()->selectRaw(
            '(SELECT COALESCE(SUM(company_share * (cash_basis - refunded_amount) / cash_basis), 0) FROM commission_batches) AS company, '
            .'(SELECT COALESCE(SUM(unallocated * (cash_basis - refunded_amount) / cash_basis), 0) FROM commission_batches) AS buffer, '
            ."(SELECT COALESCE(SUM(COALESCE(net_amount, amount)), 0) FROM withdrawals WHERE status = 'paid') AS settled_disbursements, "
            ."(SELECT COALESCE(SUM(withheld_amount), 0) FROM withdrawals WHERE status = 'paid') AS tds_tax_withheld, "
            .'(SELECT COALESCE(SUM(amount), 0) FROM commission_refunds) AS refunded',
        )->first();
        $allocated = $pools->allocated;
        $unclaimedToCompany = $pools->unclaimed_to_company;
        $company = $totals->company;
        $buffer = $totals->buffer;
        $management = app(ManagementReferral::class)->fundAmount();
        $unassigned = $pools->unassigned;
        $props['flow'] = [
            'gross_cash_inflow' => $cash,
            'dealer_cash_inflow' => $dealerCash,
            'sub_dealer_cash_inflow' => $subDealerCash,
            'channel_breakdown' => DB::table('receipts')->selectRaw('channel, SUM(amount) AS total_amount, COUNT(*) AS count')->groupBy('channel')->get(),
            'company_share_60' => round((float) $company, 2),
            'management_reserve_2' => (float) $management,
            'unassigned_territory_reserve' => (float) $unassigned,
            'old_area_reserve' => (float) $pools->old_unassigned,
            'total_company_deposits' => round((float) $company + (float) $management + (float) $unassigned, 2),
            'commission_pool_40' => (float) $allocated,
            'allocated_so_far' => (float) $allocated,
            'settled_disbursements' => (float) $totals->settled_disbursements,
            'maturing_commissions' => (float) $pools->maturing,
            'marketing_fund' => (float) $pools->marketing_fund,
            'car_bike_fund' => (float) $pools->car_bike_fund,
            'tds_tax_withheld' => (float) $totals->tds_tax_withheld,
            'refunded_purchase_value' => (float) $totals->refunded,
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
    private function benefitsFund(Request $request): array
    {
        $props = [];
        $ledger = app(RetainedFundLedger::class);
        $balance = DB::table('commission_batches')
            ->selectRaw('COALESCE(SUM(unallocated * (cash_basis - refunded_amount) / cash_basis), 0) AS amount')
            ->value('amount');
        $search = trim($request->string('q')->toString());
        $status = $request->string('status')->toString();
        $entries = CommissionAllocation::query()
            ->where('category', RetainedFundLedger::BenefitsCategory)
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->whereHas('order', fn ($orders) => $orders->where('number', 'ilike', $term)
                    ->orWhereHas('user', fn ($buyers) => $buyers->where('name', 'ilike', $term)
                        ->orWhere('dealer_code', 'ilike', $term)
                        ->orWhere('district', 'ilike', $term)));
            })
            ->when(in_array($status, self::BenefitsStatuses, true), fn ($query) => $query->where('status', $status))
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
