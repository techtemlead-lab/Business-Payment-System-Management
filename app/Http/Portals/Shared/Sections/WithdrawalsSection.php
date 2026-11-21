<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Accounts\PartnerAppointment;
use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Inventory\BranchScope;
use App\Domain\Payouts\ManualPayoutService;
use App\Domain\Payouts\PayoutBatchFiles;
use App\Domain\Payouts\PayoutLimits;
use App\Support\Money;
use App\Support\MoneyTable;
use App\Support\MoneyTableFilter;
use App\Support\MoneyTableSpec;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The office's withdrawal requests: the staff payout desk.
 */
final class WithdrawalsSection implements WorkspaceSection
{
    /** Whether an approved withdrawal is in a file sent to the bank and still waiting for it. */
    private const BankFileSql = "(CASE WHEN withdrawals.status = 'approved' AND withdrawals.exported_at IS NOT NULL THEN 'in_file' ELSE 'not_in_file' END)";

    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'payouts-hub') {
            return [];
        }
        $finance = $context->request->user()->allows('finance');

        return [
            'payment_accounts' => $finance ? app(PaymentAccounts::class)->listing($context->request->user(), false, false) : [],
            'payout_batches' => $finance ? app(PayoutBatchFiles::class)->recent() : [],
            ...$this->payoutsHub($context->request, $context->branchScope),
        ];
    }

    /**
     * Finance payout desk on the shared table. The cards add up every matching request and ignore
     * the status filter, so each card keeps its own count while staff switch status.
     *
     * @return array<string, mixed>
     */
    private function payoutsHub(Request $request, ?BranchScope $branchScope = null): array
    {
        $matching = DB::table('withdrawals')->join('users', 'users.id', '=', 'withdrawals.user_id');
        $branchScope?->restrictPartners($matching);
        $statusFilter = new MoneyTableFilter('status', (string) __('portal.pay_col_status'), 'withdrawals.status', array_map(fn (string $status): array => ['value' => $status, 'label' => (string) __(in_array($status, ['cancelled'], true) ? 'payouts.status_'.$status : 'portal.pay_status_'.$status)], ['requested', 'approved', 'rejected', 'paid', 'cancelled']));
        $dueDays = app(PayoutLimits::class)->dueDays();
        $followUp = self::followUpSql($dueDays);
        $otherFilters = [
            new MoneyTableFilter('follow_up', (string) __('payouts.filter_follow_up'), $followUp, array_map(fn (string $state): array => ['value' => $state, 'label' => (string) __('payouts.follow_up_'.$state)], ['overdue', 'on_hold', 'receipt_due'])),
            new MoneyTableFilter('bank_file', (string) __('payouts.filter_bank_file'), self::BankFileSql, [
                ['value' => 'in_file', 'label' => (string) __('payouts.bank_file_in')],
                ['value' => 'not_in_file', 'label' => (string) __('payouts.bank_file_not_in')],
            ]),
            new MoneyTableFilter('channel', (string) __('portal.pay_col_method'), 'withdrawals.channel', array_map(fn (string $channel): array => ['value' => $channel, 'label' => (string) __('portal.pay_channel_'.strtolower($channel))], PaymentAccounts::channels())),
            new MoneyTableFilter('buyer_role', (string) __('treasury.filter_buyer_role'), 'users.role', [
                ['value' => 'dealer', 'label' => (string) __('portal.acct_role_dealer')],
                ['value' => 'sub_dealer', 'label' => (string) __('portal.acct_role_sub_dealer')],
            ]),
        ];
        $spec = fn (array $filters): MoneyTableSpec => new MoneyTableSpec(
            dateColumn: 'withdrawals.created_at',
            search: ['users.name', 'users.dealer_code', 'users.phone', 'withdrawals.payment_reference'],
            filters: $filters,
            sorts: [
                'date' => 'withdrawals.created_at',
                'partner' => 'users.name',
                'method' => 'withdrawals.channel',
                'reference' => 'withdrawals.payment_reference',
                'amount' => 'withdrawals.amount',
                'withheld' => 'withdrawals.withheld_amount',
                'net' => 'withdrawals.net_amount',
                'status' => 'withdrawals.status',
            ],
            defaultSort: 'date',
            tieBreaker: 'withdrawals.id',
            sums: ['amount' => 'withdrawals.amount'],
            defaultPeriod: null,
            sumsWhen: fn (array $applied): bool => isset($applied['status']),
            perPage: 20,
        );

        $summary = MoneyTable::filtered($request, $matching, $spec($otherFilters))
            ->selectRaw("count(*) filter (where withdrawals.status = 'requested') as requested_count")
            ->selectRaw("coalesce(sum(withdrawals.amount) filter (where withdrawals.status = 'requested'), 0) as requested_amount")
            ->selectRaw("count(*) filter (where withdrawals.status = 'approved') as approved_count")
            ->selectRaw("coalesce(sum(withdrawals.amount) filter (where withdrawals.status = 'approved'), 0) as approved_amount")
            ->selectRaw("count(*) filter (where withdrawals.status = 'paid') as paid_count")
            ->selectRaw("coalesce(sum(withdrawals.amount) filter (where withdrawals.status = 'paid'), 0) as paid_amount")
            ->selectRaw("coalesce(sum(withdrawals.withheld_amount) filter (where withdrawals.status = 'paid'), 0) as paid_withheld_amount")
            ->selectRaw("count(*) filter (where {$followUp} = 'overdue') as overdue_count")
            ->selectRaw("coalesce(sum(withdrawals.amount) filter (where {$followUp} = 'overdue'), 0) as overdue_amount")
            ->selectRaw("count(*) filter (where {$followUp} = 'on_hold') as on_hold_count")
            ->selectRaw("count(*) filter (where {$followUp} = 'receipt_due') as receipt_due_count")
            ->first();

        $rows = (clone $matching)
            ->leftJoin('payout_receipts', 'payout_receipts.withdrawal_id', '=', 'withdrawals.id')
            ->leftJoin('users as payers', 'payers.id', '=', 'withdrawals.payer_id')
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
                'withdrawals.on_hold_at',
                'withdrawals.approved_at',
                'withdrawals.payer_id',
                'withdrawals.receipt_due',
                'withdrawals.payout_batch_id',
                'withdrawals.exported_at',
                'payers.name as payer_name',
                'users.status as partner_status',
                'users.name as user_name',
                'users.dealer_code',
                'users.phone',
                'users.role',
                'users.district',
                'users.division',
            ]);
        $table = MoneyTable::make($request, $rows, $spec([$statusFilter, ...$otherFilters]));
        app(PartnerAppointment::class)->attachToRows($table['rows']->getCollection());
        $table['rows']->through(fn (object $row): array => [
            'id' => $row->id,
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
            'partner_type' => $row->partner_type,
            'district' => $row->district,
            'division' => $row->division,
            'payer_state' => app(ManualPayoutService::class)->payerState($row, $request->user()),
            'waits_for_owner' => app(ManualPayoutService::class)->waitsForOwner($row, $request->user()),
            'on_hold' => $row->on_hold_at !== null,
            'partner_status' => $row->partner_status,
            'approved_at' => $row->approved_at,
            'payer_id' => $row->payer_id,
            'payer_name' => $row->payer_name,
            'receipt_due' => (bool) $row->receipt_due,
            'in_bank_file' => $row->status === 'approved' && $row->exported_at !== null,
            'bank_file' => $row->payout_batch_id !== null ? PayoutBatchFiles::shortId((string) $row->payout_batch_id) : null,
            'waiting_days' => in_array($row->status, ['requested', 'approved'], true)
                ? (int) CarbonImmutable::parse($row->status === 'approved' && $row->approved_at ? $row->approved_at : $row->created_at)->diffInDays(now())
                : null,
            'overdue' => $row->status === 'approved' && $row->approved_at !== null && CarbonImmutable::parse($row->approved_at)->addDays($dueDays)->isPast(),
        ]);

        $rules = app(CommissionPolicy::class)->snapshot()['rules'];

        return [
            'payouts_table' => $table,
            'payout_totals' => [
                'requested_count' => (int) ($summary->requested_count ?? 0),
                'requested_amount' => Money::decimal(Money::cents((string) ($summary->requested_amount ?? '0'))),
                'approved_count' => (int) ($summary->approved_count ?? 0),
                'approved_amount' => Money::decimal(Money::cents((string) ($summary->approved_amount ?? '0'))),
                'paid_count' => (int) ($summary->paid_count ?? 0),
                'paid_amount' => Money::decimal(Money::cents((string) ($summary->paid_amount ?? '0'))),
                'paid_withheld_amount' => Money::decimal(Money::cents((string) ($summary->paid_withheld_amount ?? '0'))),
                'overdue_count' => (int) ($summary->overdue_count ?? 0),
                'overdue_amount' => Money::decimal(Money::cents((string) ($summary->overdue_amount ?? '0'))),
                'on_hold_count' => (int) ($summary->on_hold_count ?? 0),
                'receipt_due_count' => (int) ($summary->receipt_due_count ?? 0),
            ],
            'payout_due_days' => $dueDays,
            'tds_rate' => (float) CommissionPolicy::rate($rules, 'payout_tax_withholding')->decimal(),
        ];
    }

    /**
     * What a withdrawal still needs from the office, as SQL: 'overdue' (approved longer than the
     * owner's due days and not paid), 'on_hold' (partner suspended), 'receipt_due' (paid, proof to
     * attach) or 'none'. The cut-off is the app's clock minus the owner's whole number of days,
     * written by Carbon, so no typed text reaches the SQL.
     */
    public static function followUpSql(int $dueDays): string
    {
        $cutoff = now()->subDays(max(1, $dueDays))->utc()->format('Y-m-d H:i:s');

        return "(CASE WHEN withdrawals.on_hold_at IS NOT NULL AND withdrawals.status IN ('requested', 'approved') THEN 'on_hold'"
            ." WHEN withdrawals.status = 'approved' AND withdrawals.approved_at < '{$cutoff}' THEN 'overdue'"
            ." WHEN withdrawals.status = 'paid' AND withdrawals.receipt_due THEN 'receipt_due' ELSE 'none' END)";
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
