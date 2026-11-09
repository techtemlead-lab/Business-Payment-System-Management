<?php

namespace App\Domain\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the event log (money_events) filled from the records the money code already writes. The
 * money code writes nothing here. Reading again from fifteen minutes back catches a transaction
 * that committed late, and the unique key makes the repeat harmless.
 *
 * @phpstan-type Feed array{name: string, source: string, query: Builder, time: string}
 */
final class EventLogProjector
{
    private const OverlapMinutes = 15;

    private const Columns = ['occurred_at', 'kind', 'action', 'source', 'source_id', 'part', 'order_id', 'order_number', 'partner_id', 'partner_name', 'partner_code', 'recipient_id', 'recipient_name', 'fund_key', 'label', 'percent', 'product_id', 'product_name', 'branch_id', 'quantity', 'amount', 'audience'];

    private const NullTypes = ['part' => 'smallint', 'order_id' => 'uuid', 'partner_id' => 'bigint', 'recipient_id' => 'bigint', 'percent' => 'numeric', 'product_id' => 'bigint', 'branch_id' => 'bigint', 'quantity' => 'integer', 'amount' => 'numeric'];

    /** order_events actions the log shows, and the event each one is. */
    private const OrderActions = ['order.approved' => 'order_approved', 'order.held' => 'order_held', 'order.rejected' => 'order_rejected', 'order.dispatched' => 'order_dispatched', 'shipment.delivered' => 'order_delivered', 'payment.claimed' => 'payment_sent'];

    private const WithdrawalEvents = ['requested', 'approved', 'rejected', 'paid'];

    private const ProgramFunds = "CASE r.program WHEN 'marketing' THEN 'marketing' WHEN 'bike' THEN 'car_bike' WHEN 'car' THEN 'car_bike' WHEN 'tour' THEN 'tour' WHEN 'additional_rank' THEN 'rank_reward' END";

    /** New rows inserted since the last run. */
    public function catchUp(int $limit = 5000): int
    {
        $watermarks = $this->watermarks();
        $inserted = 0;
        foreach ($this->feeds() as $feed) {
            $seenUntil = isset($watermarks[$feed['name']]) ? CarbonImmutable::parse($watermarks[$feed['name']]) : null;
            [$added, $newest, $seen] = $this->pass($feed, $seenUntil?->subMinutes(self::OverlapMinutes), $limit);
            $inserted += $added;
            if ($seenUntil !== null && $seen === $limit && $newest !== null && $newest <= $seenUntil) {
                // More rows inside the overlap than one run may read: carry on from where it stopped.
                [$more, $newest] = $this->pass($feed, $seenUntil, $limit);
                $inserted += $more;
            }
            if ($newest !== null && ($seenUntil === null || $newest > $seenUntil)) {
                $watermarks[$feed['name']] = $newest->toIso8601ZuluString('microsecond');
            }
        }
        $this->saveWatermarks($watermarks);

        return $inserted;
    }

    /** Empties the log and fills it again. People reading the page keep seeing the old rows until it finishes. */
    public function rebuild(): int
    {
        return DB::transaction(function (): int {
            DB::table('money_events')->delete();
            DB::table('money_event_watermarks')->delete();
            $inserted = 0;
            $watermarks = [];
            foreach ($this->feeds() as $feed) {
                [$added, $newest] = $this->pass($feed, null, null);
                $inserted += $added;
                if ($newest !== null) {
                    $watermarks[$feed['name']] = $newest->toIso8601ZuluString('microsecond');
                }
            }
            $this->saveWatermarks($watermarks);

            return $inserted;
        });
    }

    /**
     * How many entries the source records should have made in the last seven days, against the
     * log. Catches up first, so a mismatch means something is really missing.
     *
     * @return array<string, array{source: int, log: int}>
     */
    public function verify(): array
    {
        $this->catchUp();
        $since = now()->subDays(7);
        $result = [];
        foreach ($this->feeds() as $feed) {
            $count = DB::query()->fromSub((clone $feed['query'])->where($feed['time'], '>=', $since), 'feed')->count();
            $result[$feed['source']] = ['source' => ($result[$feed['source']]['source'] ?? 0) + $count, 'log' => 0];
        }
        foreach (DB::table('money_events')->where('occurred_at', '>=', $since)->groupBy('source')->selectRaw('source, COUNT(*) AS rows_logged')->get() as $row) {
            if (isset($result[$row->source])) {
                $result[$row->source]['log'] = (int) $row->rows_logged;
            }
        }

        return $result;
    }

    /**
     * Copies one feed's rows into the log, oldest first.
     *
     * @param  Feed  $feed
     * @return array{0: int, 1: ?CarbonImmutable, 2: int} rows inserted, the newest time read, rows read
     */
    private function pass(array $feed, ?CarbonImmutable $from, ?int $limit): array
    {
        $query = clone $feed['query'];
        if ($from !== null) {
            $query->where($feed['time'], '>=', $from->format('Y-m-d H:i:s.u'));
        }
        if ($limit !== null) {
            $query->orderBy($feed['time'])->limit($limit);
        }
        $inserted = DB::table('money_events')->insertOrIgnoreUsing(self::Columns, $query);
        $read = DB::query()->fromSub($query, 'q')->selectRaw('MAX(occurred_at) AS newest, COUNT(*) AS seen')->first();

        return [$inserted, $read?->newest === null ? null : CarbonImmutable::parse((string) $read->newest)->utc(), (int) ($read->seen ?? 0)];
    }

    /** @return array<string, string> feed name => the newest time already read */
    private function watermarks(): array
    {
        return DB::table('money_event_watermarks')->pluck('seen_until', 'feed')->map(fn (mixed $time): string => (string) $time)->all();
    }

    /** @param array<string, string> $watermarks */
    private function saveWatermarks(array $watermarks): void
    {
        $rows = array_map(fn (string $feed, string $time): array => ['feed' => $feed, 'seen_until' => $time, 'updated_at' => now()], array_keys($watermarks), $watermarks);
        if ($rows !== []) {
            DB::table('money_event_watermarks')->upsert($rows, ['feed'], ['seen_until', 'updated_at']);
        }
    }

    /** @return list<Feed> */
    private function feeds(): array
    {
        return [
            $this->orders(), $this->orderEvents(), $this->receipts(), $this->companyShares(), $this->ownersShares(),
            $this->allocations(), $this->movedToProfit(), $this->reversals(), $this->monthsSettled(), $this->rankBonuses(),
            $this->withdrawals(), $this->fundSpends(), $this->otherEntries(), $this->stock(), $this->orderRefunds(), ...$this->returnsToAccount(), $this->commissionRefunds(),
        ];
    }

    /**
     * The select list of one feed, with every event column present and typed.
     *
     * @param  array<string, string>  $values  column => fixed SQL
     */
    private function row(array $values): string
    {
        $parts = [];
        foreach (self::Columns as $column) {
            $parts[] = ($values[$column] ?? 'NULL::'.(self::NullTypes[$column] ?? 'text')).' AS '.$column;
        }

        return implode(', ', $parts);
    }

    private function utc(string $column): string
    {
        return "{$column} AT TIME ZONE 'UTC'";
    }

    /** @return Feed */
    private function feed(string $source, string $name, Builder $query, string $time): array
    {
        return ['name' => $name, 'source' => $source, 'query' => $query, 'time' => $time];
    }

    /** @return Feed */
    private function orders(): array
    {
        return $this->feed('orders', 'orders', DB::table('orders as o')->join('users as u', 'u.id', '=', 'o.user_id')->where('o.is_opening_balance', false)
            ->selectRaw($this->row([
                'occurred_at' => $this->utc('o.created_at'), 'kind' => "'order'", 'action' => "'order_placed'", 'source' => "'orders'", 'source_id' => 'o.id::text', 'part' => '0::smallint',
                'order_id' => 'o.id', 'order_number' => 'o.number', 'partner_id' => 'o.user_id', 'partner_name' => 'u.name', 'partner_code' => 'u.dealer_code', 'amount' => 'o.total', 'audience' => "'all'",
            ])), 'o.created_at');
    }

    /** @return Feed */
    private function orderEvents(): array
    {
        $actions = implode(' ', array_map(fn (string $from, string $to): string => "WHEN '{$from}' THEN '{$to}'", array_keys(self::OrderActions), self::OrderActions));

        return $this->feed('order_events', 'order_events', DB::table('order_events as e')->join('orders as o', 'o.id', '=', 'e.order_id')->join('users as u', 'u.id', '=', 'o.user_id')
            ->leftJoin('payment_claims as c', 'c.id', '=', DB::raw("(CASE WHEN e.action = 'payment.claimed' THEN (e.metadata->>'claim_id')::bigint END)"))
            ->whereIn('e.action', array_keys(self::OrderActions))
            ->selectRaw($this->row([
                'occurred_at' => $this->utc('e.created_at'), 'kind' => "'order'", 'action' => 'CASE e.action '.$actions.' END', 'source' => "'order_events'", 'source_id' => 'e.id::text', 'part' => '0::smallint',
                'order_id' => 'o.id', 'order_number' => 'o.number', 'partner_id' => 'o.user_id', 'partner_name' => 'u.name', 'partner_code' => 'u.dealer_code', 'amount' => "CASE WHEN e.action = 'payment.claimed' THEN c.amount ELSE o.total END", 'audience' => "'all'",
            ])), 'e.created_at');
    }

    /** @return Feed */
    private function receipts(): array
    {
        return $this->feed('receipts', 'receipts', DB::table('receipts as r')->join('orders as o', 'o.id', '=', 'r.order_id')->join('users as u', 'u.id', '=', 'o.user_id')
            ->selectRaw($this->row([
                'occurred_at' => $this->utc('r.created_at'), 'kind' => "'money_in'", 'action' => "CASE WHEN r.channel = 'Account' THEN 'paid_from_account' ELSE 'money_received' END", 'source' => "'receipts'", 'source_id' => 'r.id::text', 'part' => '0::smallint',
                'order_id' => 'o.id', 'order_number' => 'o.number', 'partner_id' => 'o.user_id', 'partner_name' => 'u.name', 'partner_code' => 'u.dealer_code', 'label' => 'r.channel', 'amount' => 'r.amount', 'audience' => "'all'",
            ])), 'r.created_at');
    }

    /** @return Feed */
    private function companyShares(): array
    {
        return $this->feed('commission_batches', 'batch_company', DB::table('commission_batches as b')->join('orders as o', 'o.id', '=', 'b.order_id')->join('users as u', 'u.id', '=', 'o.user_id')->where('b.company_share', '>', 0)
            ->selectRaw($this->row([
                'occurred_at' => $this->utc('b.created_at'), 'kind' => "'fund_in'", 'action' => "'fund_in'", 'source' => "'commission_batches'", 'source_id' => 'b.id::text', 'part' => '0::smallint',
                'order_id' => 'o.id', 'order_number' => 'o.number', 'partner_id' => 'o.user_id', 'partner_name' => 'u.name', 'partner_code' => 'u.dealer_code', 'fund_key' => "'company_share'", 'label' => "'company_share'", 'amount' => 'b.company_share', 'audience' => "'all'",
            ])), 'b.created_at');
    }

    /** @return Feed */
    private function ownersShares(): array
    {
        return $this->feed('commission_batches', 'batch_owners', DB::table('commission_batches as b')->join('orders as o', 'o.id', '=', 'b.order_id')->join('users as u', 'u.id', '=', 'o.user_id')->where('b.unallocated', '>', 0)
            ->selectRaw($this->row([
                'occurred_at' => $this->utc('b.created_at'), 'kind' => "'fund_in'", 'action' => "'fund_in'", 'source' => "'commission_batches'", 'source_id' => 'b.id::text', 'part' => '1::smallint',
                'order_id' => 'o.id', 'order_number' => 'o.number', 'partner_id' => 'o.user_id', 'partner_name' => 'u.name', 'partner_code' => 'u.dealer_code', 'fund_key' => "'benefits'", 'label' => "'owners_share'", 'amount' => 'b.unallocated', 'audience' => "'owner'",
            ])), 'b.created_at');
    }

    /** @return Feed */
    private function allocations(): array
    {
        $fund = FundCatalog::rowFundSql();
        $person = "(funds.user_id IS NOT NULL AND ({$fund}) IS NULL)";

        return $this->feed('allocations', 'allocations', DB::table('commission_allocations as funds')->join('orders as o', 'o.id', '=', 'funds.order_id')->join('users as u', 'u.id', '=', 'o.user_id')
            ->leftJoin('users as earner', 'earner.id', '=', 'funds.user_id')
            ->where('funds.category', '!=', 'company_commission_benefits_fund')->whereRaw("funds.metadata->>'source_allocation_id' IS NULL")
            ->selectRaw($this->row([
                'occurred_at' => $this->utc('funds.created_at'), 'kind' => "CASE WHEN {$person} THEN 'commission' ELSE 'fund_in' END", 'action' => "CASE WHEN {$person} THEN 'commission_to_partner' ELSE 'fund_in' END", 'source' => "'allocations'", 'source_id' => 'funds.id::text', 'part' => '0::smallint',
                'order_id' => 'o.id', 'order_number' => 'o.number', 'partner_id' => 'o.user_id', 'partner_name' => 'u.name', 'partner_code' => 'u.dealer_code',
                'recipient_id' => "CASE WHEN {$person} THEN funds.user_id END", 'recipient_name' => "CASE WHEN {$person} THEN earner.name END", 'fund_key' => $fund,
                'label' => 'LEFT(funds.category, 60)', 'percent' => 'funds.rate_percentage', 'amount' => 'COALESCE(funds.original_amount, funds.amount)', 'audience' => "'all'",
            ])), 'funds.created_at');
    }

    /** @return Feed */
    private function movedToProfit(): array
    {
        return $this->feed('allocations', 'moved_to_profit', DB::table('commission_allocations as funds')->leftJoin('orders as o', 'o.id', '=', 'funds.order_id')->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->whereRaw("funds.metadata->>'source_allocation_id' IS NOT NULL")
            ->selectRaw($this->row([
                'occurred_at' => $this->utc('funds.created_at'), 'kind' => "'fund_in'", 'action' => "'moved_to_profit'", 'source' => "'allocations'", 'source_id' => 'funds.id::text', 'part' => '0::smallint',
                'order_id' => 'o.id', 'order_number' => 'o.number', 'partner_id' => 'o.user_id', 'partner_name' => 'u.name', 'partner_code' => 'u.dealer_code', 'fund_key' => FundCatalog::rowFundSql(),
                'label' => "LEFT(COALESCE(funds.metadata->>'source_category', funds.category), 60)", 'percent' => 'funds.rate_percentage', 'amount' => 'funds.amount', 'audience' => "'all'",
            ])), 'funds.created_at');
    }

    /** @return Feed */
    private function reversals(): array
    {
        $owners = "funds.category = 'company_commission_benefits_fund'";

        return $this->feed('commission_reversals', 'reversals', DB::table('commission_reversals as r')->join('commission_allocations as funds', 'funds.id', '=', 'r.allocation_id')
            ->leftJoin('orders as o', 'o.id', '=', 'funds.order_id')->leftJoin('users as u', 'u.id', '=', 'o.user_id')->leftJoin('users as earner', 'earner.id', '=', 'r.user_id')
            ->selectRaw($this->row([
                'occurred_at' => $this->utc('r.created_at'), 'kind' => "'commission'", 'action' => "'commission_taken_back'", 'source' => "'commission_reversals'", 'source_id' => 'r.id::text', 'part' => '0::smallint',
                'order_id' => 'o.id', 'order_number' => 'o.number', 'partner_id' => 'o.user_id', 'partner_name' => 'u.name', 'partner_code' => 'u.dealer_code', 'recipient_id' => 'r.user_id', 'recipient_name' => 'earner.name',
                'fund_key' => "CASE WHEN {$owners} THEN 'benefits' ELSE (".FundCatalog::rowFundSql().') END', 'label' => 'LEFT(funds.category, 60)', 'amount' => 'r.amount', 'audience' => "CASE WHEN {$owners} THEN 'owner' ELSE 'all' END",
            ])), 'r.created_at');
    }

    /** @return Feed */
    private function monthsSettled(): array
    {
        return $this->feed('commission_settlement_runs', 'settlement_runs', DB::table('commission_settlement_runs as s')->whereIn('s.kind', ['salary', 'royalty'])->whereRaw("COALESCE(s.result->>'status', '') <> 'empty_pool'")
            ->selectRaw($this->row([
                'occurred_at' => $this->utc('s.created_at'), 'kind' => "'month_close'", 'action' => "'month_settled'", 'source' => "'commission_settlement_runs'", 'source_id' => 's.id::text', 'part' => '0::smallint',
                'label' => 's.kind', 'amount' => "COALESCE((s.result->>'total_distributed')::numeric, 0)", 'audience' => "'all'",
            ])), 's.created_at');
    }

    /** @return Feed */
    private function rankBonuses(): array
    {
        return $this->feed('rank_incentive_awards', 'rank_awards', DB::table('rank_incentive_awards as a')->join('users as earner', 'earner.id', '=', 'a.user_id')
            ->selectRaw($this->row([
                'occurred_at' => $this->utc('a.created_at'), 'kind' => "'fund_out'", 'action' => "'rank_bonus_paid'", 'source' => "'rank_incentive_awards'", 'source_id' => 'a.id::text', 'part' => '0::smallint',
                'recipient_id' => 'a.user_id', 'recipient_name' => 'earner.name', 'fund_key' => "'rank_bonus'", 'label' => "'rank_incentive_pool'", 'amount' => 'a.amount', 'audience' => "'all'",
            ])), 'a.created_at');
    }

    /** @return Feed */
    private function withdrawals(): array
    {
        return $this->feed('withdrawal_events', 'withdrawal_events', DB::table('withdrawal_events as e')->join('withdrawals as w', 'w.id', '=', 'e.withdrawal_id')->join('users as u', 'u.id', '=', 'w.user_id')
            ->whereIn('e.event', self::WithdrawalEvents)
            ->selectRaw($this->row([
                'occurred_at' => $this->utc('e.created_at'), 'kind' => "'withdrawal'", 'action' => "('withdrawal_' || e.event)", 'source' => "'withdrawal_events'", 'source_id' => 'e.id::text', 'part' => '0::smallint',
                'partner_id' => 'w.user_id', 'partner_name' => 'u.name', 'partner_code' => 'u.dealer_code', 'amount' => 'w.amount', 'audience' => "'all'",
            ])), 'e.created_at');
    }

    /** @return Feed */
    private function fundSpends(): array
    {
        $award = "starts_with(j.event_key, 'reserve.award.')";
        $ownerFunds = "'".implode("','", CompanyFunds::OwnerOnly)."'";

        return $this->feed('journal_entries', 'fund_spends', DB::table('journal_entries as j')
            ->join('reserve_disbursements as r', 'r.id', '=', DB::raw("(CASE WHEN j.event_key ~ '^(fund\\.spend|reserve\\.award|reserve\\.expense)\\.[0-9]+$' THEN split_part(j.event_key, '.', 3)::bigint END)"))
            ->leftJoin('fund_expense_heads as h', 'h.id', '=', 'r.expense_head_id')->leftJoin('users as earner', 'earner.id', '=', 'r.user_id')
            ->whereRaw("(starts_with(j.event_key, 'fund.spend.') OR starts_with(j.event_key, 'reserve.award.') OR starts_with(j.event_key, 'reserve.expense.'))")
            ->selectRaw($this->row([
                'occurred_at' => $this->utc('j.created_at'), 'kind' => "'fund_out'", 'action' => "CASE WHEN {$award} THEN 'reward_given' ELSE 'fund_spent' END", 'source' => "'journal_entries'", 'source_id' => 'j.id::text', 'part' => '0::smallint',
                'recipient_id' => 'r.user_id', 'recipient_name' => 'COALESCE(r.payee_name, earner.name)', 'fund_key' => 'COALESCE(r.fund_key, '.self::ProgramFunds.')',
                'label' => 'LEFT(COALESCE(h.name_bn, h.name_en, r.program), 60)', 'amount' => '(SELECT SUM(l.debit) FROM journal_lines l WHERE l.entry_id = j.id)',
                'audience' => "CASE WHEN r.fund_key IN ({$ownerFunds}) THEN 'owner' ELSE 'all' END",
            ])), 'j.created_at');
    }

    /** @return Feed */
    private function otherEntries(): array
    {
        $clinic = "starts_with(j.event_key, 'clinic.dispense.')";
        $payroll = "starts_with(j.event_key, 'payroll.paid.')";

        return $this->feed('journal_entries', 'other_entries', DB::table('journal_entries as j')
            ->whereRaw("(starts_with(j.event_key, 'clinic.dispense.') OR starts_with(j.event_key, 'opening.') OR starts_with(j.event_key, 'payroll.paid.'))")
            ->selectRaw($this->row([
                'occurred_at' => $this->utc('j.created_at'), 'kind' => "CASE WHEN {$clinic} THEN 'fund_in' WHEN {$payroll} THEN 'fund_out' ELSE 'money_in' END",
                'action' => "CASE WHEN {$clinic} THEN 'clinic_sale' WHEN {$payroll} THEN 'payroll_paid' ELSE 'opening_balance' END", 'source' => "'journal_entries'", 'source_id' => 'j.id::text', 'part' => '0::smallint',
                'fund_key' => "CASE WHEN {$clinic} THEN 'clinic' END", 'amount' => '(SELECT SUM(l.debit) FROM journal_lines l WHERE l.entry_id = j.id)', 'audience' => "CASE WHEN {$payroll} THEN 'payroll' ELSE 'all' END",
            ])), 'j.created_at');
    }

    /** @return Feed */
    private function stock(): array
    {
        return $this->feed('stock_movements', 'stock', DB::table('stock_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')
            ->selectRaw($this->row([
                'occurred_at' => $this->utc('m.created_at'), 'kind' => "'stock'", 'action' => "CASE WHEN m.quantity > 0 THEN 'product_in' ELSE 'product_out' END", 'source' => "'stock_movements'", 'source_id' => 'm.id::text', 'part' => '0::smallint',
                'label' => 'LEFT(m.kind, 60)', 'product_id' => 'm.product_id', 'product_name' => 'p.name', 'branch_id' => 'm.branch_id', 'quantity' => 'ABS(m.quantity)', 'audience' => "'all'",
            ])), 'm.created_at');
    }

    /** @return Feed */
    private function orderRefunds(): array
    {
        $toAccount = OrderMoneyReport::refundToAccountSql();

        return $this->feed('order_refunds', 'order_refunds', DB::table('order_refunds as f')->join('orders as o', 'o.id', '=', 'f.order_id')->join('users as u', 'u.id', '=', 'f.user_id')
            ->whereRaw("f.amount - {$toAccount} > 0")
            ->selectRaw($this->row([
                'occurred_at' => $this->utc('f.created_at'), 'kind' => "'order'", 'action' => "'order_refunded'", 'source' => "'order_refunds'", 'source_id' => 'f.id::text', 'part' => '0::smallint',
                'order_id' => 'o.id', 'order_number' => 'o.number', 'partner_id' => 'f.user_id', 'partner_name' => 'u.name', 'partner_code' => 'u.dealer_code', 'amount' => "f.amount - {$toAccount}", 'audience' => "'all'",
            ])), 'f.created_at');
    }

    /** @return list<Feed> */
    private function returnsToAccount(): array
    {
        if (OrderMoneyReport::refundToAccountSql() === '0') {
            return [];
        }

        return [$this->feed('order_refunds', 'returned_to_account', DB::table('order_refunds as f')->join('orders as o', 'o.id', '=', 'f.order_id')->join('users as u', 'u.id', '=', 'f.user_id')
            ->where('f.to_account', '>', 0)
            ->selectRaw($this->row([
                'occurred_at' => $this->utc('f.created_at'), 'kind' => "'order'", 'action' => "'returned_to_account'", 'source' => "'order_refunds'", 'source_id' => 'f.id::text', 'part' => '1::smallint',
                'order_id' => 'o.id', 'order_number' => 'o.number', 'partner_id' => 'f.user_id', 'partner_name' => 'u.name', 'partner_code' => 'u.dealer_code', 'amount' => 'f.to_account', 'audience' => "'all'",
            ])), 'f.created_at')];
    }

    /** @return Feed */
    private function commissionRefunds(): array
    {
        return $this->feed('commission_refunds', 'commission_refunds', DB::table('commission_refunds as f')->join('orders as o', 'o.id', '=', 'f.order_id')->join('users as u', 'u.id', '=', 'o.user_id')
            ->selectRaw($this->row([
                'occurred_at' => $this->utc('f.created_at'), 'kind' => "'commission'", 'action' => "'commission_refund'", 'source' => "'commission_refunds'", 'source_id' => 'f.id::text', 'part' => '0::smallint',
                'order_id' => 'o.id', 'order_number' => 'o.number', 'partner_id' => 'o.user_id', 'partner_name' => 'u.name', 'partner_code' => 'u.dealer_code', 'amount' => 'f.amount', 'audience' => "'all'",
            ])), 'f.created_at');
    }
}
