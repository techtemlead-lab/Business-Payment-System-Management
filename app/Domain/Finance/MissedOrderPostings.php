<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionPosting;
use App\Models\Order;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Orders delivered and paid in full that never got their commission posted, because posting was off or in
 * preview mode when they were delivered (posting then writes nothing and is not tried again).
 *
 * The same list feeds the readiness check and `finance:post-missed-orders`. Posting goes through the normal
 * CommissionPosting::process, which posts an order once only and keeps its price-funding refusal.
 */
final class MissedOrderPostings
{
    public function __construct(private CommissionPosting $posting) {}

    /**
     * Delivered orders, paid in full, bought by a dealer or sub-dealer, with no commission batch and no commission
     * row. Opening-balance orders never post, so they are left out.
     */
    public function query(): Builder
    {
        return DB::table('orders as o')
            ->join('users as u', 'u.id', '=', 'o.user_id')
            ->where('o.status', 'delivered')
            ->where('o.is_opening_balance', false)
            ->where('o.total', '>', 0)
            ->whereColumn('o.paid', 'o.total')
            ->whereIn('u.role', ['dealer', 'sub_dealer'])
            ->whereNotExists(fn (Builder $batches) => $batches->selectRaw('1')->from('commission_batches as b')->whereColumn('b.order_id', 'o.id'))
            ->whereNotExists(fn (Builder $rows) => $rows->selectRaw('1')->from('commission_allocations as a')->whereColumn('a.order_id', 'o.id'));
    }

    public function exist(): bool
    {
        return $this->query()->exists();
    }

    /**
     * Every missed order, oldest first.
     *
     * @return list<array{id: string, number: string, total: string, partner: string, code: ?string, created_at: string}>
     */
    public function list(?int $limit = null): array
    {
        return $this->query()->orderBy('o.created_at')->orderBy('o.id')
            ->when($limit !== null, fn (Builder $query) => $query->limit((int) $limit))
            ->get(['o.id', 'o.number', 'o.total', 'u.name', 'u.dealer_code', 'o.created_at'])
            ->map(fn (object $row): array => ['id' => (string) $row->id, 'number' => (string) $row->number, 'total' => Money::decimal(Money::cents((string) $row->total)),
                'partner' => (string) $row->name, 'code' => $row->dealer_code, 'created_at' => (string) $row->created_at])
            ->values()->all();
    }

    /**
     * Posts each missed order through the normal posting. An order that is refused (price does not fund the
     * company share and commissions, figures do not add up) or fails is reported and the rest go on.
     *
     * @param  list<string>|null  $orderIds  Only these orders; null for every missed order.
     * @return array{posted: list<array{id: string, number: string, total: string}>, refused: list<array{id: string, number: string, total: string, reason: string}>}
     */
    public function post(?array $orderIds = null): array
    {
        $posted = [];
        $refused = [];
        $missed = array_filter($this->list(), fn (array $row): bool => $orderIds === null || in_array($row['id'], $orderIds, true));
        foreach ($missed as $row) {
            $line = ['id' => $row['id'], 'number' => $row['number'], 'total' => $row['total']];
            try {
                $order = Order::query()->findOrFail($row['id']);
                $this->posting->process($order);
                if (DB::table('commission_batches')->where('order_id', $row['id'])->exists()) {
                    $posted[] = $line;
                } else {
                    $refused[] = [...$line, 'reason' => (string) __('money_core.missed_not_posted')];
                }
            } catch (ValidationException $refusal) {
                $refused[] = [...$line, 'reason' => implode(' ', $refusal->validator->errors()->all())];
            } catch (Throwable $error) {
                report($error);
                $refused[] = [...$line, 'reason' => $error->getMessage()];
            }
        }
        if ($posted !== [] || $refused !== []) {
            Audit::record(null, 'commissions.missed_orders_posted', 'finance:post-missed-orders', [
                'posted' => array_column($posted, 'id'),
                'refused' => array_map(fn (array $line): array => ['id' => $line['id'], 'reason' => $line['reason']], $refused),
            ]);
        }

        return ['posted' => $posted, 'refused' => $refused];
    }
}
