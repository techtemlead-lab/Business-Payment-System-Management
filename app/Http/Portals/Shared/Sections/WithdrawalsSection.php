<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Accounts\PartnerEarningsPresenter;
use App\Domain\Accounts\PartnerFullReport;
use App\Domain\Accounts\PartnerWalletStatement;
use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Finance\FinanceSettings;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Inventory\BranchScope;
use App\Domain\Orders\OrderListFilter;
use App\Domain\Payouts\PayoutReceipts;
use App\Domain\Security\SelfApproval;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Partner money out: wallet and earnings, withdrawal history, the full statement, and the staff payout desk.
 */
final class WithdrawalsSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        return match ($context->section) {
            'withdrawals', 'earnings' => $this->earnings($context),
            'full-report' => $this->fullReport($context),
            'payouts-hub' => $this->payoutsHub($context->request, $context->branchScope),
            'payment-receipts' => $this->paymentReceipts($context),
            default => [],
        };
    }

    /**
     * Whether the person viewing may record this approved payment: 'alone' when they approved it
     * themselves and nobody else could pay, 'second_person' when someone else must, otherwise null.
     */
    private function payerState(object $row, User $viewer): mixed
    {
        if ($row->status !== 'approved' || (int) $row->reviewed_by !== (int) $viewer->id) {
            return null;
        }

        return app(SelfApproval::class)->isAvailable($viewer, [$row->user_id, $row->reviewed_by]) ? 'alone' : 'second_person';
    }

    /**
     * Wallet, the earnings ledger for the chosen period, settlement payouts and withdrawal history.
     *
     * @return array<string, mixed>
     */
    private function earnings(SectionContext $context): array
    {
        $request = $context->request;
        $user = $context->user;
        $props = [];
        $presenter = app(PartnerEarningsPresenter::class);
        $preset = $request->string('preset')->toString() ?: null;
        $resolved = $presenter->resolvePreset($preset, SectionDates::optionalDate($request->input('from')), SectionDates::optionalDate($request->input('to')));
        $earningsFrom = $resolved['from'];
        $earningsTo = $resolved['to'];
        [$earningsFromUtc, $earningsToUtc] = SectionDates::businessDayBounds($earningsFrom, $earningsTo);
        $props['wallet'] = DB::table('wallets')->where('user_id', $user->id)->first(['available', 'held', 'commission_held']);
        $props['withdrawal_balances'] = $presenter->withdrawalBalances($user);
        $props['withdrawal_kyc_block'] = app(FinanceSettings::class)->needsKycBeforeWithdrawal($user);
        $props['earnings_filters'] = ['from' => $earningsFrom, 'to' => $earningsTo, 'preset' => $preset];
        $props['earnings'] = $presenter->ledgerRows($user, $earningsFromUtc, $earningsToUtc, 500);
        // The period total comes from the database, so it covers every row in the dates (not only
        // the rows on screen) and leaves out commission that was taken back.
        $props['period_totals'] = [
            'commission' => Money::decimal(Money::cents((string) DB::table('commission_allocations')
                ->where('user_id', $user->id)
                ->whereNotIn('status', ['reversed', 'forfeited'])
                ->when($earningsFromUtc, fn ($query) => $query->where('created_at', '>=', $earningsFromUtc))
                ->when($earningsToUtc, fn ($query) => $query->where('created_at', '<', $earningsToUtc))
                ->sum('amount'))),
        ];
        $props['settlement_payouts'] = DB::table('commission_settlement_payouts')->where('user_id', $user->id)->latest()->limit(30)->get(['id', 'kind', 'period_month', 'amount', 'reversed_amount', 'created_at']);
        $props['withdrawals'] = $this->withdrawalHistory($request, $user, $context->portal, $context->section === 'withdrawals', $context->branchScope);
        if ($context->section === 'withdrawals') {
            $props['withdrawal_status_counts'] = $this->withdrawalStatusCounts($request, $user, $context->portal, $context->branchScope);
        }
        if (in_array($user->role, ['dealer', 'sub_dealer'], true) && $context->section === 'earnings') {
            $props['wallet_statement'] = app(PartnerWalletStatement::class)->for($user, $earningsFrom, $earningsTo);
        }

        return $props;
    }

    /**
     * The partner's full statement for the chosen period.
     *
     * @return array<string, mixed>
     */
    private function fullReport(SectionContext $context): array
    {
        $request = $context->request;
        $user = $context->user;
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true), 403);
        $props = [];
        $presenter = app(PartnerEarningsPresenter::class);
        $preset = $request->string('preset')->toString() ?: null;
        $resolved = $presenter->resolvePreset($preset, SectionDates::optionalDate($request->input('from')), SectionDates::optionalDate($request->input('to')));
        $reportFrom = $resolved['from'];
        $reportTo = $resolved['to'];
        $props['full_report'] = app(PartnerFullReport::class)->for($user, $reportFrom, $reportTo);
        $props['full_report']['filters']['preset'] = $preset;
        // Certificates the partner can download: a yearly income certificate always, a rank certificate once they hold a rank.
        $props['full_report']['certificates'] = [
            'years' => range((int) now('Asia/Dhaka')->format('Y'), max(2024, (int) ($user->created_at?->timezone('Asia/Dhaka')->format('Y') ?? 2024))),
            'rank' => $user->role === 'dealer' && DB::table('dealer_ranks')->where('user_id', $user->id)->whereNotIn('current_rank', ['', 'dealer'])->exists(),
        ];

        return $props;
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function withdrawalHistory(Request $request, User $user, string $portal, bool $applyFilters, ?BranchScope $branchScope = null): LengthAwarePaginator
    {
        $query = $this->filteredWithdrawals($request, $user, $portal, $applyFilters, true, $branchScope);

        return $this->presentHistory($query);
    }

    /**
     * Withdrawals per status under every other filter on screen, for the status
     * tabs above the list. The status filter is left out so every tab keeps its count.
     *
     * @return array<string, int>
     */
    private function withdrawalStatusCounts(Request $request, User $user, string $portal, ?BranchScope $branchScope = null): array
    {
        return $this->filteredWithdrawals($request, $user, $portal, true, false, $branchScope)
            ->select('withdrawals.status')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('withdrawals.status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * On a branch picked in the top bar, staff see only withdrawals of that branch's partners.
     */
    private function filteredWithdrawals(Request $request, User $user, string $portal, bool $applyFilters, bool $withStatus, ?BranchScope $branchScope = null): Builder
    {
        $staff = in_array($portal, ['manage', 'sadmin'], true);
        $query = DB::table('withdrawals')
            ->join('users', 'users.id', '=', 'withdrawals.user_id')
            ->when(! $staff, fn ($rows) => $rows->where('withdrawals.user_id', $user->id))
            ->select([
                'withdrawals.id',
                'withdrawals.amount',
                'withdrawals.withheld_amount',
                'withdrawals.net_amount',
                'withdrawals.channel',
                'withdrawals.destination',
                'withdrawals.status',
                'withdrawals.reason',
                'withdrawals.payment_reference',
                'withdrawals.paid_at',
                'withdrawals.created_at',
                'users.name',
                'users.dealer_code',
                'users.role',
                'users.phone',
                'users.district',
                'users.division',
            ]);
        if ($staff) {
            $branchScope?->restrictPartners($query);
        }

        if ($applyFilters) {
            if ($request->filled('q')) {
                $term = '%'.OrderListFilter::escapeLike($request->string('q')->trim()->toString()).'%';
                $query->where(function ($rows) use ($term): void {
                    $rows->where('users.name', 'ilike', $term)
                        ->orWhere('users.dealer_code', 'ilike', $term)
                        ->orWhere('users.phone', 'ilike', $term)
                        ->orWhere('withdrawals.payment_reference', 'ilike', $term)
                        ->orWhere('withdrawals.reason', 'ilike', $term);
                });
            }
            if ($staff && $request->filled('buyer_role')) {
                $query->where('users.role', $request->string('buyer_role')->toString());
            }
            $status = $request->string('status')->toString();
            if ($withStatus && in_array($status, ['requested', 'approved', 'rejected', 'paid'], true)) {
                $query->where('withdrawals.status', $status);
            }
            $channel = $request->string('channel')->toString();
            if (in_array($channel, PaymentAccounts::channels(), true)) {
                $query->where('withdrawals.channel', $channel);
            }
            $range = OrderListFilter::createdRange(
                $request->string('period')->toString(),
                $request->filled('from') ? $request->string('from')->toString() : null,
                $request->filled('to') ? $request->string('to')->toString() : null,
            );
            if ($range !== null) {
                $query->where('withdrawals.created_at', '>=', $range['from'])
                    ->where('withdrawals.created_at', '<=', $range['until']);
            }
        }

        return $query;
    }

    private function presentHistory(Builder $query): LengthAwarePaginator
    {
        return $query->latest('withdrawals.created_at')->paginate(20)->withQueryString()->through(fn (object $row): array => [
            'id' => $row->id,
            'amount' => $row->amount,
            'withheld_amount' => $row->withheld_amount,
            'net_amount' => $row->net_amount,
            'channel' => $row->channel,
            'destination_masked' => $this->maskedDestination(is_string($row->destination) ? $row->destination : null),
            'status' => $row->status,
            'reason' => $row->reason,
            'payment_reference' => $row->payment_reference,
            'paid_at' => $row->paid_at,
            'created_at' => $row->created_at,
            'name' => $row->name,
            'dealer_code' => $row->dealer_code,
            'role' => $row->role,
            'phone' => $row->phone,
            'district' => $row->district,
            'division' => $row->division,
        ]);
    }

    /**
     * The dealer's or sub-dealer's own payout receipts. Staff never reach this
     * section (it exists only in the partner portals).
     *
     * @return array{payment_receipts: LengthAwarePaginator}
     */
    private function paymentReceipts(SectionContext $context): array
    {
        abort_unless(in_array($context->user->role, ['dealer', 'sub_dealer'], true), 403);

        return ['payment_receipts' => app(PayoutReceipts::class)->forPartner($context->user)];
    }

    /**
     * Finance payout desk. Search and status run on the server, and the summary
     * cards add up every matching request (not just the visible page). The cards
     * ignore the status tab so each card keeps its own count while staff switch tabs.
     *
     * @return array{withdrawals: LengthAwarePaginator<int, array<string, mixed>>, payout_totals: array{requested_count: int, requested_amount: string, approved_count: int, approved_amount: string, paid_count: int, paid_amount: string, paid_withheld_amount: string}, tds_rate: float}
     */
    private function payoutsHub(Request $request, ?BranchScope $branchScope = null): array
    {
        $matching = DB::table('withdrawals')->join('users', 'users.id', '=', 'withdrawals.user_id');
        $branchScope?->restrictPartners($matching);
        $search = $request->string('q')->trim()->toString();
        if ($search !== '') {
            $term = '%'.OrderListFilter::escapeLike($search).'%';
            $matching->where(function ($rows) use ($term): void {
                $rows->where('users.name', 'ilike', $term)
                    ->orWhere('users.dealer_code', 'ilike', $term)
                    ->orWhere('users.phone', 'ilike', $term)
                    ->orWhere('withdrawals.payment_reference', 'ilike', $term);
            });
        }
        if ($request->filled('buyer_role')) {
            $matching->where('users.role', $request->string('buyer_role')->toString());
        }
        // Method and request dates, so finance can work one bKash or bank batch at a time.
        $channel = $request->string('channel')->toString();
        if (in_array($channel, PaymentAccounts::channels(), true)) {
            $matching->where('withdrawals.channel', $channel);
        }
        $range = OrderListFilter::createdRange(
            $request->string('period')->toString(),
            $request->filled('from') ? $request->string('from')->toString() : null,
            $request->filled('to') ? $request->string('to')->toString() : null,
        );
        if ($range !== null) {
            $matching->where('withdrawals.created_at', '>=', $range['from'])->where('withdrawals.created_at', '<=', $range['until']);
        }

        $summary = (clone $matching)
            ->selectRaw("count(*) filter (where withdrawals.status = 'requested') as requested_count")
            ->selectRaw("coalesce(sum(withdrawals.amount) filter (where withdrawals.status = 'requested'), 0) as requested_amount")
            ->selectRaw("count(*) filter (where withdrawals.status = 'approved') as approved_count")
            ->selectRaw("coalesce(sum(withdrawals.amount) filter (where withdrawals.status = 'approved'), 0) as approved_amount")
            ->selectRaw("count(*) filter (where withdrawals.status = 'paid') as paid_count")
            ->selectRaw("coalesce(sum(withdrawals.amount) filter (where withdrawals.status = 'paid'), 0) as paid_amount")
            ->selectRaw("coalesce(sum(withdrawals.withheld_amount) filter (where withdrawals.status = 'paid'), 0) as paid_withheld_amount")
            ->first();

        $status = $request->string('status')->toString();
        $withdrawals = (clone $matching)
            ->when(in_array($status, ['requested', 'approved', 'rejected', 'paid'], true), fn ($rows) => $rows->where('withdrawals.status', $status))
            ->leftJoin('payout_receipts', 'payout_receipts.withdrawal_id', '=', 'withdrawals.id')
            ->select([
                'payout_receipts.id as payout_receipt_id',
                'payout_receipts.number as payout_receipt_number',
                'payout_receipts.method as payout_method',
                'withdrawals.id',
                'withdrawals.user_id',
                'withdrawals.amount',
                'withdrawals.withheld_amount',
                'withdrawals.net_amount',
                'withdrawals.channel',
                'withdrawals.destination',
                'withdrawals.status',
                'withdrawals.reason',
                'withdrawals.payment_reference',
                'withdrawals.paid_at',
                'withdrawals.created_at',
                'withdrawals.reviewed_by',
                'users.name as user_name',
                'users.dealer_code',
                'users.phone',
                'users.role',
                'users.district',
                'users.division',
            ])
            ->latest('withdrawals.created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (object $row): array => [
                'id' => $row->id,
                'payer_state' => $this->payerState($row, $request->user()),
                'user_id' => $row->user_id,
                'user_name' => $row->user_name,
                'dealer_code' => $row->dealer_code,
                'phone' => $row->phone,
                'amount' => $row->amount,
                'withheld_amount' => $row->withheld_amount,
                'net_amount' => $row->net_amount,
                'channel' => $row->channel,
                'destination_masked' => $this->maskedDestination(is_string($row->destination) ? $row->destination : null),
                'status' => $row->status,
                'reason' => $row->reason,
                'payment_reference' => $row->payment_reference,
                'paid_at' => $row->paid_at,
                'created_at' => $row->created_at,
                'payout_receipt_id' => $row->payout_receipt_id,
                'payout_receipt_number' => $row->payout_receipt_number,
                'payout_method' => $row->payout_method,
                'role' => $row->role,
                'district' => $row->district,
                'division' => $row->division,
            ]);

        $rules = app(CommissionPolicy::class)->snapshot()['rules'];

        return [
            'withdrawals' => $withdrawals,
            'payout_totals' => [
                'requested_count' => (int) ($summary->requested_count ?? 0),
                'requested_amount' => Money::decimal(Money::cents((string) ($summary->requested_amount ?? '0'))),
                'approved_count' => (int) ($summary->approved_count ?? 0),
                'approved_amount' => Money::decimal(Money::cents((string) ($summary->approved_amount ?? '0'))),
                'paid_count' => (int) ($summary->paid_count ?? 0),
                'paid_amount' => Money::decimal(Money::cents((string) ($summary->paid_amount ?? '0'))),
                'paid_withheld_amount' => Money::decimal(Money::cents((string) ($summary->paid_withheld_amount ?? '0'))),
            ],
            'tds_rate' => (float) CommissionPolicy::rate($rules, 'payout_tax_withholding')->decimal(),
        ];
    }

    private function maskedDestination(?string $encrypted): ?string
    {
        if ($encrypted === null || $encrypted === '') {
            return null;
        }
        try {
            $plain = (string) decrypt($encrypted);
        } catch (\Throwable) {
            return null;
        }
        if ($plain === '') {
            return null;
        }
        $visible = mb_substr($plain, -4);

        return str_repeat('•', max(0, mb_strlen($plain) - 4)).$visible;
    }
}
