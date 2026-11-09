<?php

namespace App\Domain\Finance;

use App\Domain\Inventory\BranchScope;
use App\Domain\Orders\MixedOutcomeDecisions;
use App\Models\Order;
use App\Support\Money;
use App\Support\MoneyTable;
use App\Support\MoneyTableFilter;
use App\Support\MoneyTableSpec;
use App\Support\PaymentChannel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The accountant's report: one row per delivered order, and a detail page showing where every
 * taka of that order went. Money counts only after delivery, so a partly delivered order counts
 * only what was delivered.
 *
 * @phpstan-type OrderMoney array{order: array<string, mixed>, posting: string, no_commission: array{reason: ?string, amount: string}|null, basis: array{cash: string, mrp: string, refunded: string}|null, lines: list<array<string, mixed>>, lines_total: string, adds_up: bool}
 */
final class OrderMoneyReport
{
    private const PartlyDelivered = MixedOutcomeDecisions::PartlyDelivered;

    private const Counted = ['delivered', self::PartlyDelivered];

    /** @return array<string, mixed> MoneyTable payload */
    public function table(Request $request, ?BranchScope $scope): array
    {
        $paid = self::paidSql();
        $orders = DB::table('orders as o')->join('users as u', 'u.id', '=', 'o.user_id')->leftJoin('order_refunds as f', 'f.order_id', '=', 'o.id')
            ->whereIn('o.status', self::Counted)->where('o.is_opening_balance', false)->where('o.paid', '>', 0)
            ->selectRaw('o.id::text AS id, o.number, o.created_at, o.status, u.name AS partner_name, u.dealer_code AS partner_code, u.role AS partner_role, '.self::mrpSql()." AS mrp_total, {$paid} AS paid, (SELECT r.channel FROM receipts r WHERE r.order_id = o.id ORDER BY r.amount DESC, r.id LIMIT 1) AS method");
        $scope?->restrictOrders($orders, 'o.fulfillment_branch_id');

        $table = MoneyTable::make($request, DB::query()->fromSub($orders, 'x'), new MoneyTableSpec(
            dateColumn: 'x.created_at',
            search: ['x.number', 'x.partner_name', 'x.partner_code'],
            filters: $this->filters(),
            sorts: ['date' => 'x.created_at', 'number' => 'x.number', 'partner' => 'x.partner_name', 'mrp_total' => 'x.mrp_total', 'paid' => 'x.paid'],
            defaultSort: 'date',
            tieBreaker: 'x.id',
            sums: ['mrp_total' => 'x.mrp_total', 'paid' => 'x.paid'],
        ));
        $table['rows'] = $table['rows']->through(fn (object $row): array => [
            'id' => $row->id,
            'number' => $row->number,
            'created_at' => CarbonImmutable::parse((string) $row->created_at, 'UTC')->toIso8601String(),
            'partner_name' => $row->partner_name,
            'partner_code' => $row->partner_code,
            'partner_role' => $row->partner_role,
            'mrp_total' => (string) $row->mrp_total,
            'paid' => (string) $row->paid,
        ]);

        return $table;
    }

    /**
     * MRP total of an order, on alias `o`. A partly delivered order counts delivered parcels only.
     */
    public static function mrpSql(): string
    {
        return "CASE WHEN o.status = '".self::PartlyDelivered."' THEN ".self::deliveredPartSql('l.mrp')
            .' ELSE (SELECT COALESCE(SUM(l.mrp * l.quantity), 0) FROM order_lines l WHERE l.order_id = o.id AND NOT l.is_free) END';
    }

    /**
     * Money kept for an order, on aliases `o` and `f` (order_refunds). A partly delivered order
     * counts only what its delivered parcels were sold for.
     */
    public static function paidSql(): string
    {
        return "CASE WHEN o.status = '".self::PartlyDelivered."' THEN LEAST(".self::deliveredPartSql('l.unit_price').', o.paid - COALESCE(f.amount, 0)) ELSE o.paid - COALESCE(f.amount, 0) END';
    }

    /**
     * Cash the partner handed over for an order's delivered goods, on aliases `o` and `f`. Money
     * paid from their own account is not cash.
     */
    public static function cashGivenSql(): string
    {
        $toAccount = self::refundToAccountSql();
        $cash = "GREATEST((SELECT COALESCE(SUM(r.amount), 0) FROM receipts r WHERE r.order_id = o.id AND r.channel <> 'Account') - COALESCE(f.amount - {$toAccount}, 0), 0)";

        return "CASE WHEN o.status = '".self::PartlyDelivered."' THEN LEAST(".self::deliveredPartSql('l.unit_price').", {$cash}) ELSE {$cash} END";
    }

    /** How much of a refund went back to the partner's own account, on alias `f`. */
    public static function refundToAccountSql(): string
    {
        return once(fn (): bool => Schema::hasColumn('order_refunds', 'to_account')) ? 'COALESCE(f.to_account, 0)' : '0';
    }

    /** Orders that count as delivered goods, on aliases `o` and `f`. */
    public static function deliveredOrders(): Builder
    {
        return DB::table('orders as o')->leftJoin('order_refunds as f', 'f.order_id', '=', 'o.id')
            ->whereIn('o.status', self::Counted)->where('o.is_opening_balance', false);
    }

    /** @return OrderMoney */
    public function detail(Order $order, bool $showOwnerFunds): array
    {
        $batch = DB::table('commission_batches')->where('order_id', $order->id)->first();
        $figures = DB::table('orders as o')->leftJoin('order_refunds as f', 'f.order_id', '=', 'o.id')->where('o.id', $order->id)
            ->selectRaw(self::mrpSql().' AS mrp_total, '.self::paidSql().' AS paid, '.self::deliveredPartSql('l.unit_price').' AS delivered_cash')->first();
        $rows = DB::table('commission_allocations as funds')->where('funds.order_id', $order->id)
            ->where('funds.category', '!=', 'company_commission_benefits_fund')->whereRaw("funds.metadata->>'source_allocation_id' IS NULL")
            ->orderBy('funds.id')->selectRaw('funds.*, '.FundCatalog::rowFundSql().' AS fund_key')->get();
        $decision = DB::table('order_events')->where('order_id', $order->id)->where('action', MixedOutcomeDecisions::Action)->value('metadata');
        $decision = is_string($decision) ? json_decode($decision, true) : null;

        $lines = $batch === null ? [] : [...$this->companyLines($batch, $showOwnerFunds), ...$this->allocationLines($rows)];
        $linesTotal = array_sum(array_map(fn (array $line): int => FundCatalog::centsOf($line['amount']), $lines));
        $sold = $batch !== null ? (string) $batch->cash_basis : ($order->status === self::PartlyDelivered ? (string) $figures->delivered_cash : (string) $order->total);
        $mrp = $batch !== null ? (string) $batch->mrp_basis : (string) $figures->mrp_total;

        return [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'created_at' => CarbonImmutable::parse((string) $order->created_at, 'UTC')->toIso8601String(),
                'status' => $order->status,
                'partner' => $this->buyer((int) $order->user_id),
                'mrp_total' => (string) $figures->mrp_total,
                'paid' => (string) $figures->paid,
                'order_total' => (string) $order->total,
                'percent_off' => $this->percentOff($mrp, $sold),
                'payments' => $this->payments($order->id),
            ],
            'posting' => $this->posting($order, $batch, $rows, $decision),
            'no_commission' => $this->noCommission($order, $rows, $decision),
            'basis' => $batch === null ? null : ['cash' => (string) $batch->cash_basis, 'mrp' => (string) $batch->mrp_basis, 'refunded' => (string) $batch->refunded_amount],
            'lines' => $lines,
            'lines_total' => FundCatalog::decimalOf($linesTotal),
            'adds_up' => $batch === null || $linesTotal === Money::cents((string) $batch->cash_basis),
        ];
    }

    /**
     * The money one delivered parcel line brought in, added up over the delivered parcels of `o`.
     */
    private static function deliveredPartSql(string $unitColumn): string
    {
        return "(SELECT COALESCE(SUM({$unitColumn} * (part.item->>'quantity')::int), 0) FROM shipments s CROSS JOIN LATERAL json_array_elements(s.lines) AS part(item) JOIN order_lines l ON l.id = (part.item->>'line_id')::bigint AND NOT l.is_free WHERE s.order_id = o.id AND s.status = 'delivered')";
    }

    /** @return list<MoneyTableFilter> */
    private function filters(): array
    {
        $roles = array_map(fn (string $role): array => ['value' => $role, 'label' => (string) __('treasury.role_'.$role)], ['dealer', 'sub_dealer']);
        $methods = array_map(fn (string $method): array => ['value' => $method, 'label' => PaymentChannel::word($method)], ['bKash', 'Nagad', 'Rocket', 'Bank', 'Office', 'Account']);
        $statuses = array_map(fn (string $status): array => ['value' => $status, 'label' => (string) __('treasury.order_status_'.$status)], self::Counted);

        return [
            new MoneyTableFilter('buyer_role', (string) __('treasury.filter_buyer_role'), 'x.partner_role', $roles),
            new MoneyTableFilter('method', (string) __('treasury.filter_method'), 'x.method', $methods),
            new MoneyTableFilter('status', (string) __('treasury.filter_status'), 'x.status', $statuses),
        ];
    }

    /**
     * The company share and the owners' share. Anyone but an owner on the owner panel sees them
     * merged into one line with no percent, so the parts still add up and the owners' share stays hidden.
     *
     * @return list<array<string, mixed>>
     */
    private function companyLines(object $batch, bool $showOwnerFunds): array
    {
        $company = FundCatalog::centsOf($batch->company_share);
        $owners = FundCatalog::centsOf($batch->unallocated);
        $companyNow = $this->scaledCents($batch, $company);
        $ownersNow = $this->scaledCents($batch, $owners);
        $name = FundCatalog::name('company_share');
        if (! $showOwnerFunds) {
            return [$this->fundLine('company_share', $name, null, null, $company + $owners, ($company + $owners) - ($companyNow + $ownersNow), 'company_share', $name)];
        }
        $policy = is_string($batch->policy) ? json_decode($batch->policy, true) : [];
        $lines = [$this->fundLine('company_share', $name, isset($policy['company_mrp_percent']) ? (string) $policy['company_mrp_percent'] : null, 'mrp', $company, $company - $companyNow, 'company_share', $name)];
        if ($owners > 0) {
            $ownersName = FundCatalog::name('benefits');
            $lines[] = $this->fundLine('owners_share', $ownersName, null, null, $owners, $owners - $ownersNow, 'benefits', $ownersName);
        }

        return $lines;
    }

    private function scaledCents(object $batch, int $cents): int
    {
        $cash = FundCatalog::centsOf($batch->cash_basis);
        $left = $cash - FundCatalog::centsOf($batch->refunded_amount);

        return $cash === 0 ? 0 : (int) round($cents * $left / $cash, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * @return array<string, mixed>
     */
    private function fundLine(string $key, string $typeLabel, ?string $percent, ?string $percentOf, int $amount, int $returned, ?string $fundKey, string $fundName, ?string $reason = null): array
    {
        return ['key' => $key, 'type_label' => $typeLabel, 'percent' => $percent, 'percent_of' => $percentOf, 'amount' => Money::decimal($amount), 'returned' => Money::decimal(max(0, $returned)),
            'to' => ['kind' => 'fund', 'key' => $fundKey, 'name' => $fundName], 'status' => 'done', 'status_label' => (string) __('treasury.line_status_done'), 'reason' => $reason];
    }

    /**
     * @param  iterable<object>  $rows
     * @return list<array<string, mixed>>
     */
    private function allocationLines(iterable $rows): array
    {
        $rows = collect($rows);
        $partners = DB::table('users')->whereIn('id', $rows->pluck('user_id')->filter()->unique()->values())->get(['id', 'name', 'dealer_code', 'district', 'division', 'profile'])->keyBy('id');
        $metadata = $rows->mapWithKeys(fn (object $row): array => [$row->id => is_string($row->metadata) ? (array) json_decode($row->metadata, true) : []]);
        $places = DB::table('place_codes')->whereIn('id', $metadata->pluck('place_code_id')->filter()->unique()->values())->get(['id', 'name', 'code'])->keyBy('id');

        return $rows->map(function (object $row) use ($partners, $metadata, $places): array {
            $amount = FundCatalog::centsOf($row->original_amount ?? $row->amount);
            $meta = $metadata[$row->id];
            $reason = $row->category === CompanyFunds::NoCommissionCategory && is_string($meta['reason'] ?? null) ? $meta['reason'] : null;
            $status = $this->lineStatus((string) $row->status);

            return [
                'key' => $row->category.'-'.$row->id,
                'type_label' => FundCatalog::typeLabel((string) $row->category),
                'percent' => (string) $row->rate_percentage,
                'percent_of' => 'paid',
                'amount' => Money::decimal($amount),
                'returned' => Money::decimal(max(0, $amount - FundCatalog::centsOf($row->amount))),
                'to' => $this->wentTo($row, $partners, $places, $meta),
                'status' => $status,
                'status_label' => (string) __('treasury.line_status_'.$status),
                'reason' => $reason,
            ];
        })->values()->all();
    }

    private function lineStatus(string $status): string
    {
        return match ($status) {
            'maturing' => 'waiting',
            'held' => 'held',
            'forfeited' => 'moved',
            'reversed' => 'returned',
            default => 'done',
        };
    }

    /**
     * Where one commission line went: a partner with their name and area, or just the fund.
     *
     * @param  \Illuminate\Support\Collection<int|string, object>  $partners
     * @param  \Illuminate\Support\Collection<int|string, object>  $places
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function wentTo(object $row, $partners, $places, array $meta): array
    {
        if ($row->fund_key !== null) {
            return ['kind' => 'fund', 'key' => $row->fund_key, 'name' => FundCatalog::name((string) $row->fund_key)];
        }
        $partner = $row->user_id !== null ? $partners->get($row->user_id) : null;
        if ($partner === null) {
            return ['kind' => 'fund', 'key' => null, 'name' => FundCatalog::typeLabel((string) $row->category)];
        }
        $place = str_starts_with((string) $row->category, 'area_') && isset($meta['place_code_id']) ? $places->get($meta['place_code_id']) : null;

        return ['kind' => 'partner', 'id' => (int) $partner->id, 'name' => $partner->name, 'code' => $partner->dealer_code,
            'area' => $place !== null ? trim($place->name.' · '.$place->code) : self::areaOf($partner)];
    }

    /** A partner's area: their place code when they have one, otherwise district and division. */
    public static function areaOf(object $partner): ?string
    {
        $profile = is_string($partner->profile ?? null) ? json_decode($partner->profile, true) : ($partner->profile ?? null);
        $code = is_array($profile) ? ($profile['place_code'] ?? $profile['area_code'] ?? null) : null;
        if (is_string($code) && $code !== '') {
            return $code;
        }
        $parts = array_filter([$partner->district ?? null, $partner->division ?? null], fn ($part): bool => is_string($part) && $part !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }

    /** @return array{id: int, name: ?string, code: ?string, role: ?string, area: ?string} */
    private function buyer(int $userId): array
    {
        $user = DB::table('users')->where('id', $userId)->first(['id', 'name', 'dealer_code', 'role', 'district', 'division', 'profile']);

        return ['id' => $userId, 'name' => $user?->name, 'code' => $user?->dealer_code, 'role' => $user?->role, 'area' => $user !== null ? self::areaOf($user) : null];
    }

    private function percentOff(string $mrp, string $sold): ?string
    {
        $mrpCents = Money::cents($mrp);

        return $mrpCents === 0 ? null : number_format((($mrpCents - Money::cents($sold)) * 100) / $mrpCents, 2, '.', '');
    }

    /** @return list<array{method: string, method_label: string, amount: string, reference: string, confirmed_by: ?string}> */
    private function payments(string $orderId): array
    {
        return DB::table('receipts as r')->leftJoin('payment_claims as c', 'c.id', '=', 'r.claim_id')->leftJoin('users as staff', 'staff.id', '=', 'c.verified_by')
            ->where('r.order_id', $orderId)->orderBy('r.id')->get(['r.channel', 'r.amount', 'r.reference', 'staff.name as confirmed_by'])
            ->map(fn (object $row): array => ['method' => (string) $row->channel, 'method_label' => PaymentChannel::word((string) $row->channel), 'amount' => (string) $row->amount, 'reference' => (string) $row->reference, 'confirmed_by' => $row->confirmed_by])
            ->all();
    }

    /**
     * @param  iterable<object>  $rows
     * @param  array<string, mixed>|null  $decision
     */
    private function posting(Order $order, ?object $batch, iterable $rows, ?array $decision): string
    {
        if ($this->noCommissionRows($rows)->isNotEmpty() || ($decision['decision'] ?? null) === MixedOutcomeDecisions::NoCommission) {
            return 'no_commission';
        }
        if ($batch !== null) {
            $policy = is_string($batch->policy) ? json_decode($batch->policy, true) : [];

            return isset($policy['delivered_part']) ? 'part_delivered' : 'posted';
        }

        return $order->status === 'delivered' ? 'no_commission' : 'waiting_delivery';
    }

    /**
     * @param  iterable<object>  $rows
     * @param  array<string, mixed>|null  $decision
     * @return array{reason: ?string, amount: string}|null
     */
    private function noCommission(Order $order, iterable $rows, ?array $decision): ?array
    {
        $noCommissionRows = $this->noCommissionRows($rows);
        if ($noCommissionRows->isEmpty() && ($decision['decision'] ?? null) !== MixedOutcomeDecisions::NoCommission) {
            return null;
        }
        $reason = $noCommissionRows->map(fn (object $row): mixed => is_string($row->metadata) ? (json_decode($row->metadata, true)['reason'] ?? null) : null)->filter()->first();

        return [
            'reason' => is_string($reason) ? $reason : (is_string($decision['reason'] ?? null) ? $decision['reason'] : null),
            'amount' => Money::decimal($noCommissionRows->sum(fn (object $row): int => FundCatalog::centsOf($row->original_amount ?? $row->amount))),
        ];
    }

    /**
     * @param  iterable<object>  $rows
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function noCommissionRows(iterable $rows)
    {
        return collect($rows)->where('category', CompanyFunds::NoCommissionCategory)->values();
    }
}
