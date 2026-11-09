<?php

namespace App\Domain\Orders;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionReversal;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Inventory\BranchOperations;
use App\Models\Order;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Closure;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orders with a mixed outcome: one parcel delivered and another received back. Once every parcel
 * has finished, the order's final status is "partly delivered" and commission posts on the
 * delivered goods by default, the same way it posts on a whole order. Goods that came back are
 * already in stock again.
 *
 * Staff can still choose no commission, with a required reason. Then nobody keeps the commission:
 * it goes to the company profit fund with that reason, and whatever already reached partners is
 * taken back. An order that could not post by itself waits for staff, who choose either way.
 *
 * A decision is made once per order and cannot be changed here. It posts in the same transaction,
 * so the decision and the money stand or fall together.
 */
final class MixedOutcomeDecisions
{
    /** The order status for a finished mixed outcome. */
    public const PartlyDelivered = 'partly_delivered';

    public const Action = 'order.mixed_outcome_decided';

    /** The order history line for commission posted by itself on the delivered goods. */
    public const PostedAction = 'order.delivered_part_commission_posted';

    public const DeliveredPart = 'delivered_part';

    public const NoCommission = 'no_commission';

    public const Choices = [self::DeliveredPart, self::NoCommission];

    /** Most orders listed at a time. */
    private const ListLimit = 50;

    public function __construct(
        private readonly CommissionPosting $commissions,
        private readonly BranchOperations $branches,
        private readonly CommissionReversal $reversal,
    ) {}

    /**
     * @param  Builder  $orderIds  the order ids this reader may see
     * @return list<object>
     */
    public function pending(Builder $orderIds): array
    {
        return $this->waiting()
            ->whereIn('orders.id', clone $orderIds)
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->orderBy('orders.updated_at')
            ->limit(self::ListLimit)
            ->get(['orders.id as order_id', 'orders.number', 'users.name as partner'])
            ->all();
    }

    /**
     * @return array{fund_amount: int|null, recovered: int, still_owed: int} what the choice did to the money, in cents
     */
    public function decide(User $actor, Order $order, string $choice, string $reason): array
    {
        abort_unless($actor->allows('order_review'), 403);
        abort_if((int) $actor->id === (int) $order->user_id, 403, __('portal.err_self_review_order'));
        abort_unless($this->branches->mayOpenOrder($actor, $order), 403);

        return DB::transaction(function () use ($actor, $order, $choice, $reason): array {
            CommissionWallet::lock();
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (DB::table('order_events')->where('order_id', $locked->id)->where('action', self::Action)->exists()) {
                throw ValidationException::withMessages(['decision' => __('courier_more.err_decision_already')]);
            }
            $open = $choice === self::NoCommission ? $this->undecided() : $this->waiting();
            if (! $open->where('orders.id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['decision' => __('courier_more.err_decision_not_needed')]);
            }
            $metadata = ['decision' => $choice, 'reason' => $reason];
            $money = ['fund_amount' => null, 'recovered' => 0, 'still_owed' => 0];
            if ($choice === self::DeliveredPart) {
                $metadata['commission_posted'] = $this->commissions->processDeliveredPart($locked, $actor->id) !== [];
            } else {
                $money = DB::table('commission_batches')->where('order_id', $locked->id)->exists()
                    ? $this->reversal->takeBackForNoCommission($locked, $reason, $actor->id)
                    : [...$money, 'fund_amount' => $this->commissions->retainDeliveredPartForCompany($locked, $actor->id, $reason)];
                $metadata += array_filter(['fund_amount' => $money['fund_amount'] === null ? null : Money::decimal($money['fund_amount']),
                    'taken_back' => $money['recovered'] > 0 ? Money::decimal($money['recovered']) : null,
                    'still_owed' => $money['still_owed'] > 0 ? Money::decimal($money['still_owed']) : null], fn (?string $value): bool => $value !== null);
            }
            DB::table('order_events')->insert(['order_id' => $locked->id, 'actor_id' => $actor->id, 'action' => self::Action,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            Audit::record($actor->id, self::Action, $locked->id, $metadata);
            $partner = User::query()->find($locked->user_id);
            $locale = $partner?->preferredLocale() ?? app()->getLocale();
            $replace = ['number' => $locked->number, 'decision' => __('schemes_commission.decision_'.$choice, [], $locale), 'reason' => $reason];
            Audit::notice($locked->user_id, __('courier_more.notice_decision_title', $replace, $locale), __('courier_more.notice_decision_body', $replace, $locale));

            return $money;
        }, 3);
    }

    /**
     * What happened to the commission of a partly delivered order, for its page. Only staff are
     * given the fund figures and the choice.
     *
     * @return array<string, mixed>|null
     */
    public function outcomeFor(Order $order, User $viewer): ?array
    {
        if ($order->status !== self::PartlyDelivered) {
            return null;
        }
        $decision = DB::table('order_events')->where('order_id', $order->id)->where('action', self::Action)->latest('id')->first();
        $decided = $decision !== null ? json_decode((string) $decision->metadata, true, 512, JSON_THROW_ON_ERROR) : [];
        $batch = DB::table('commission_batches')->where('order_id', $order->id)->first();
        $state = match (true) {
            ($decided['decision'] ?? null) === self::NoCommission => 'no_commission',
            $batch !== null => 'posted',
            default => 'waiting',
        };
        $staff = $viewer->staff();

        return [
            'state' => $state,
            'delivered_value' => DeliveredMoney::valueOf($order),
            'order_total' => (string) $order->total,
            'reason' => $decided['reason'] ?? null,
            'can_choose_no_commission' => $staff && $viewer->allows('order_review') && (int) $viewer->id !== (int) $order->user_id
                && $this->undecided()->where('orders.id', $order->id)->exists(),
            ...($staff ? ['fund_amount' => $decided['fund_amount'] ?? null, 'taken_back' => $decided['taken_back'] ?? null, 'still_owed' => $decided['still_owed'] ?? null] : []),
        ];
    }

    /**
     * Gives a mixed order its final status once its last parcel has finished, then posts commission
     * on the delivered goods as a normal delivery would. Runs inside the transaction that records
     * the parcel's outcome, with the money lock and the order lock held. Commission that cannot
     * post must not undo the parcel's outcome, so the order then waits for staff instead.
     */
    public static function closeIfFinished(Order $locked, ?int $actorId = null): void
    {
        if (! in_array($locked->status, ['dispatched', 'partially_dispatched'], true) || ! self::finished()->where('orders.id', $locked->id)->exists()) {
            return;
        }
        $locked->update(['status' => self::PartlyDelivered]);
        try {
            $posted = app(CommissionPosting::class)->processDeliveredPart($locked, $actorId) !== [];
        } catch (ValidationException) {
            return;
        }
        if ($posted) {
            DB::table('order_events')->insert(['order_id' => $locked->id, 'actor_id' => $actorId, 'action' => self::PostedAction,
                'metadata' => json_encode(['delivered_value' => DeliveredMoney::valueOf($locked)], JSON_THROW_ON_ERROR), 'created_at' => now()]);
        }
    }

    /** Finished mixed orders that have not posted and have no decision: staff must choose. */
    private function waiting(): QueryBuilder
    {
        return $this->undecided()->whereNotExists(fn (QueryBuilder $query) => $query->selectRaw('1')->from('commission_batches')
            ->whereColumn('commission_batches.order_id', 'orders.id'));
    }

    /** Finished mixed orders with no decision yet, posted or not. */
    private function undecided(): QueryBuilder
    {
        return self::finished()
            ->whereNotExists(fn (QueryBuilder $query) => $query->selectRaw('1')->from('order_events')
                ->whereColumn('order_events.order_id', 'orders.id')->where('order_events.action', self::Action));
    }

    /** Delivered and received-back parcels, with nothing on the road or left to send. */
    private static function finished(): QueryBuilder
    {
        $parcel = fn (string $status): Closure => fn (QueryBuilder $query) => $query->selectRaw('1')->from('shipments')
            ->whereColumn('shipments.order_id', 'orders.id')->where('shipments.status', $status);

        return DB::table('orders')
            ->whereNotIn('orders.status', ['cancelled', 'rejected', 'delivered'])
            ->whereExists($parcel('delivered'))
            ->whereExists($parcel('returned'))
            ->whereNotExists($parcel('dispatched'))
            ->whereNotExists(fn (QueryBuilder $query) => $query->selectRaw('1')->from('order_lines')
                ->whereColumn('order_lines.order_id', 'orders.id')->whereColumn('order_lines.dispatched', '<', 'order_lines.quantity'));
    }
}
