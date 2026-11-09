<?php

namespace App\Domain\Finance;

use App\Models\User;
use App\Support\MoneyTable;
use App\Support\MoneyTableFilter;
use App\Support\MoneyTableSpec;
use App\Support\PaymentChannel;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Treasury event log: every order, payment, commission, fund movement, withdrawal and stock
 * movement in time order. Owner-only rows are left out for everyone else.
 */
final class EventLog
{
    private const Actions = ['order_placed', 'order_approved', 'order_held', 'order_rejected', 'order_dispatched', 'order_delivered', 'payment_sent', 'money_received', 'paid_from_account', 'fund_in', 'commission_to_partner', 'moved_to_profit', 'commission_taken_back', 'month_settled', 'rank_bonus_paid', 'withdrawal_requested', 'withdrawal_approved', 'withdrawal_rejected', 'withdrawal_paid', 'fund_spent', 'reward_given', 'clinic_sale', 'opening_balance', 'payroll_paid', 'product_in', 'product_out', 'order_refunded', 'returned_to_account', 'commission_refund'];

    private const Kinds = ['order', 'money_in', 'commission', 'fund_in', 'fund_out', 'withdrawal', 'stock', 'month_close'];

    public function __construct(private EventLogProjector $projector) {}

    /** @return array<string, mixed> MoneyTable payload */
    public function table(Request $request, User $viewer, string $portal): array
    {
        Cache::lock('money-events', 10)->get(fn () => $this->projector->catchUp(2000));
        $showOwnerFunds = TreasuryAccess::showsOwnerFunds($viewer, $portal);
        $mayReadPayroll = $viewer->owner() || $viewer->allows('payroll');
        $query = DB::table('money_events as x')
            ->when(! $showOwnerFunds, fn ($rows) => $rows->where('x.audience', '!=', 'owner'))
            ->when(! $mayReadPayroll, fn ($rows) => $rows->where('x.audience', '!=', 'payroll'))
            ->select('x.*');

        $table = MoneyTable::make($request, $query, new MoneyTableSpec(
            dateColumn: 'x.occurred_at',
            search: ['x.order_number', 'x.partner_name', 'x.partner_code', 'x.product_name'],
            filters: $this->filters($showOwnerFunds),
            sorts: ['date' => 'x.occurred_at', 'amount' => 'x.amount'],
            defaultSort: 'date',
            tieBreaker: 'x.id',
            sums: ['amount' => 'x.amount'],
            defaultPeriod: 'today',
            sumsWhen: fn (array $filters): bool => isset($filters['action']) || isset($filters['fund']),
            countCap: 100000,
        ));
        $table['rows'] = $table['rows']->through(fn (object $row): array => [
            'id' => (int) $row->id,
            'occurred_at' => CarbonImmutable::parse((string) $row->occurred_at)->utc()->toIso8601String(),
            'kind' => $row->kind,
            'action' => $row->action,
            'action_label' => (string) __('treasury.action_'.$row->action),
            'label' => $this->labelOf($row),
            'order_id' => $row->order_id,
            'order_number' => $row->order_number,
            'partner_name' => $row->partner_name,
            'partner_code' => $row->partner_code,
            'recipient_name' => $row->recipient_name,
            'fund_key' => $row->fund_key,
            'fund_name' => $row->fund_key === null ? null : FundCatalog::name((string) $row->fund_key),
            'product_name' => $row->product_name,
            'quantity' => $row->quantity === null ? null : (int) $row->quantity,
            'amount' => $row->amount === null ? null : (string) $row->amount,
            'percent' => $row->percent === null ? null : (string) $row->percent,
        ]);

        return $table;
    }

    /** @return list<MoneyTableFilter> */
    private function filters(bool $showOwnerFunds): array
    {
        return [
            new MoneyTableFilter('action', (string) __('treasury.filter_action'), 'x.action', array_map(fn (string $action): array => ['value' => $action, 'label' => (string) __('treasury.action_'.$action)], self::Actions)),
            new MoneyTableFilter('kind', (string) __('treasury.filter_kind'), 'x.kind', array_map(fn (string $kind): array => ['value' => $kind, 'label' => (string) __('treasury.kind_'.$kind)], self::Kinds)),
            new MoneyTableFilter('fund', (string) __('treasury.filter_fund'), 'x.fund_key', array_map(fn (string $fund): array => ['value' => $fund, 'label' => FundCatalog::name($fund)], FundCatalog::visibleKeys($showOwnerFunds))),
        ];
    }

    private function labelOf(object $row): ?string
    {
        if ($row->label === null) {
            return null;
        }

        return match ($row->source) {
            'receipts' => PaymentChannel::word((string) $row->label),
            'commission_settlement_runs' => $this->word('portal.settle_kind_'.$row->label, (string) $row->label),
            'stock_movements' => $this->word('portal.stock_kind_'.$row->label, (string) $row->label),
            'journal_entries' => (string) $row->label,
            default => FundCatalog::typeLabel((string) $row->label),
        };
    }

    private function word(string $key, string $fallback): string
    {
        $word = (string) __($key);

        return $word === $key ? $fallback : $word;
    }
}
