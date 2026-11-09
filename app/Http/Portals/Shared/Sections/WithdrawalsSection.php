<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Inventory\BranchScope;
use App\Domain\Payouts\ManualPayoutService;
use App\Support\Money;
use App\Support\MoneyTable;
use App\Support\MoneyTableFilter;
use App\Support\MoneyTableSpec;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The office's withdrawal requests: the staff payout desk.
 */
final class WithdrawalsSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'payouts-hub') {
            return [];
        }

        return [
            'payment_accounts' => $context->request->user()->allows('finance') ? app(PaymentAccounts::class)->listing($context->request->user(), false, false) : [],
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
        $statusFilter = new MoneyTableFilter('status', (string) __('portal.pay_col_status'), 'withdrawals.status', array_map(fn (string $status): array => ['value' => $status, 'label' => (string) __('portal.pay_status_'.$status)], ['requested', 'approved', 'rejected', 'paid']));
        $otherFilters = [
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
            ->first();

        $rows = (clone $matching)
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
            ]);
        $table = MoneyTable::make($request, $rows, $spec([$statusFilter, ...$otherFilters]));
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
            'district' => $row->district,
            'division' => $row->division,
            'payer_state' => app(ManualPayoutService::class)->payerState($row, $request->user()),
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
