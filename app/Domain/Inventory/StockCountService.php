<?php

namespace App\Domain\Inventory;

use App\Domain\Security\SelfApproval;
use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\StockCountLine;
use App\Models\StockCountSession;
use App\Models\User;
use App\Support\Audit;
use App\Support\ReasonFields;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stock counts per branch. Each line remembers what the system held at the moment it was
 * counted, and approval posts only the difference. Goods sold or received between the count
 * and the approval are therefore left alone. Stock changes go through {@see StockService}.
 */
final class StockCountService
{
    /** A branch not counted for this many days is overdue. */
    public const OverdueDays = 30;

    public function __construct(
        private BranchOperations $branches,
        private StockService $stock,
        private StockLots $lots,
    ) {}

    public function start(User $actor, int $branchId): StockCountSession
    {
        $this->assertMay($actor, 'create');
        $branch = $this->branches->resolveWritableBranch($actor, $branchId);

        return DB::transaction(function () use ($actor, $branch): StockCountSession {
            CompanyBranch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $alreadyOpen = StockCountSession::query()
                ->where('branch_id', $branch->id)
                ->whereIn('status', [StockCountSession::StatusCounting, StockCountSession::StatusSubmitted])
                ->exists();
            if ($alreadyOpen) {
                throw ValidationException::withMessages(['branch_id' => __('purchasing.err_count_already_open')]);
            }

            $session = StockCountSession::query()->create([
                'branch_id' => $branch->id,
                'status' => StockCountSession::StatusCounting,
                'started_by' => $actor->id,
            ]);

            $onHand = BranchProductStock::query()->where('branch_id', $branch->id)->pluck('quantity', 'product_id');
            $heldHere = $onHand->filter(fn (mixed $quantity): bool => (int) $quantity !== 0)->keys()->all();
            $timestamp = now();
            $rows = Product::query()
                ->where(fn ($products) => $products->where('active', true)->orWhereIn('id', $heldHere))
                ->orderBy('name')
                ->pluck('id')
                ->map(fn (int $productId): array => [
                    'stock_count_session_id' => $session->id,
                    'product_id' => $productId,
                    'expected_quantity' => (int) ($onHand[$productId] ?? 0),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            foreach ($rows->chunk(500) as $chunk) {
                StockCountLine::query()->insert($chunk->values()->all());
            }

            Audit::record($actor->id, 'stock_count.started', (string) $session->id, ['branch_code' => $branch->code]);

            return $session;
        });
    }

    /**
     * Saves counted quantities. Lines whose number did not change keep the system figure and
     * the person from their first save, so a repeat save cannot blur the count.
     *
     * @param  list<array{line_id:int,counted_quantity:int|null}>  $rows
     */
    public function saveCounts(User $actor, StockCountSession $session, array $rows): StockCountSession
    {
        $this->assertMay($actor, 'create');
        $branch = $this->branches->resolveWritableBranch($actor, (int) $session->branch_id);

        return DB::transaction(function () use ($actor, $session, $rows, $branch): StockCountSession {
            $locked = $this->lockSession($session);
            if ($locked->status !== StockCountSession::StatusCounting) {
                throw ValidationException::withMessages(['count' => __('purchasing.err_count_not_open')]);
            }

            $ids = array_map(fn (array $row): int => (int) $row['line_id'], $rows);
            /** @var \Illuminate\Database\Eloquent\Collection<int, StockCountLine> $lines */
            $lines = StockCountLine::query()
                ->where('stock_count_session_id', $locked->id)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($rows as $row) {
                $line = $lines->get((int) $row['line_id']);
                if (! $line) {
                    throw ValidationException::withMessages(['lines' => __('purchasing.err_count_line_unknown')]);
                }
                $counted = $row['counted_quantity'] === null ? null : (int) $row['counted_quantity'];
                if ($line->counted_quantity === $counted) {
                    continue;
                }
                if ($counted === null) {
                    $line->forceFill(['counted_quantity' => null, 'counted_by' => null, 'counted_at' => null])->save();

                    continue;
                }
                // Same lock order as a stock change: product first, then the branch's stock row.
                $product = Product::query()->whereKey($line->product_id)->lockForUpdate()->firstOrFail();
                $stock = $this->branches->ensureBranchStock($branch, $product);
                $line->forceFill([
                    'expected_quantity' => (int) $stock->quantity,
                    'counted_quantity' => $counted,
                    'counted_by' => $actor->id,
                    'counted_at' => now(),
                ])->save();
            }

            return $locked;
        });
    }

    public function submit(User $actor, StockCountSession $session): StockCountSession
    {
        $this->assertMay($actor, 'create');
        $this->branches->resolveWritableBranch($actor, (int) $session->branch_id);

        return DB::transaction(function () use ($actor, $session): StockCountSession {
            $locked = $this->lockSession($session);
            if ($locked->status !== StockCountSession::StatusCounting) {
                throw ValidationException::withMessages(['count' => __('purchasing.err_count_not_open')]);
            }
            $counted = StockCountLine::query()
                ->where('stock_count_session_id', $locked->id)
                ->whereNotNull('counted_quantity')
                ->count();
            if ($counted === 0) {
                throw ValidationException::withMessages(['count' => __('purchasing.err_nothing_counted')]);
            }
            $locked->forceFill([
                'status' => StockCountSession::StatusSubmitted,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
            ])->save();
            Audit::record($actor->id, 'stock_count.submitted', (string) $locked->id, ['counted_lines' => $counted]);

            return $locked;
        });
    }

    /**
     * A second person signs off, or the owner signs off a count they took part in. Each
     * variance is posted as a stock adjustment; if any one cannot be posted, nothing is.
     */
    public function approve(User $actor, StockCountSession $session, ?string $note = null): StockCountSession
    {
        $this->assertMay($actor, 'approve');
        $branch = $this->branches->resolveWritableBranch($actor, (int) $session->branch_id);

        return DB::transaction(function () use ($actor, $session, $note, $branch): StockCountSession {
            $locked = $this->lockSession($session);
            if ($locked->status === StockCountSession::StatusApproved) {
                return $locked;
            }
            if ($locked->status !== StockCountSession::StatusSubmitted) {
                throw ValidationException::withMessages(['count' => __('purchasing.err_count_not_submitted')]);
            }

            /** @var \Illuminate\Database\Eloquent\Collection<int, StockCountLine> $lines */
            $lines = StockCountLine::query()
                ->where('stock_count_session_id', $locked->id)
                ->whereNotNull('counted_quantity')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $selfApproved = $this->assertMayDecide($actor, $locked, $lines);

            $posted = [];
            foreach ($lines as $line) {
                $variance = (int) $line->variance();
                if ($variance === 0) {
                    continue;
                }
                $reference = (string) Str::uuid();
                $this->postVariance($actor, $locked, $line, $variance, $reference);
                $line->forceFill(['stock_reference' => $reference])->save();
                $posted[] = [
                    'product_id' => $line->product_id,
                    'expected' => $line->expected_quantity,
                    'counted' => $line->counted_quantity,
                    'variance' => $variance,
                    'reference' => $reference,
                ];
            }

            $locked->forceFill([
                'status' => StockCountSession::StatusApproved,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();
            Audit::record($actor->id, 'stock_count.approved', (string) $locked->id, [
                'branch_code' => $branch->code,
                'started_by' => $locked->started_by,
                'submitted_by' => $locked->submitted_by,
                'counted_lines' => $lines->count(),
                'variances' => $posted,
                'note' => $note,
                ...($selfApproved ? ['self_approved' => true] : []),
            ]);

            return $locked;
        });
    }

    public function reject(User $actor, StockCountSession $session, string $note): StockCountSession
    {
        $this->assertMay($actor, 'approve');
        $this->branches->resolveWritableBranch($actor, (int) $session->branch_id);

        return DB::transaction(function () use ($actor, $session, $note): StockCountSession {
            $locked = $this->lockSession($session);
            if ($locked->status !== StockCountSession::StatusSubmitted) {
                throw ValidationException::withMessages(['count' => __('purchasing.err_count_not_submitted')]);
            }
            $lines = StockCountLine::query()
                ->where('stock_count_session_id', $locked->id)
                ->whereNotNull('counted_quantity')
                ->get();
            $selfApproved = $this->assertMayDecide($actor, $locked, $lines);
            $locked->forceFill([
                'status' => StockCountSession::StatusRejected,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();
            Audit::record($actor->id, 'stock_count.rejected', (string) $locked->id, ['note' => $note, ...($selfApproved ? ['self_approved' => true] : [])]);

            return $locked;
        });
    }

    /**
     * Closes a count that has not been decided, so the branch can start again. Nothing has
     * been posted to stock yet. A submitted count needs a written reason while the owner keeps
     * the `stock.count_cancel` reason compulsory for the person cancelling.
     */
    public function cancel(User $actor, StockCountSession $session, ?string $reason = null): StockCountSession
    {
        $this->assertMay($actor, 'edit');
        $this->branches->resolveWritableBranch($actor, (int) $session->branch_id);

        return DB::transaction(function () use ($actor, $session, $reason): StockCountSession {
            $locked = $this->lockSession($session);
            if (! in_array($locked->status, [StockCountSession::StatusCounting, StockCountSession::StatusSubmitted], true)) {
                throw ValidationException::withMessages(['count' => __('purchasing.err_count_not_open')]);
            }
            if ($locked->status === StockCountSession::StatusSubmitted
                && mb_strlen(trim((string) $reason)) < SelfApproval::MinimumReasonLength
                && ReasonFields::required('stock.count_cancel', $actor)) {
                throw ValidationException::withMessages(['count' => __('self_approval.err_reason')]);
            }
            $previousStatus = $locked->status;
            $locked->forceFill(['status' => StockCountSession::StatusCancelled])->save();
            Audit::record($actor->id, 'stock_count.cancelled', (string) $locked->id, ['previous_status' => $previousStatus, 'reason' => $reason]);

            return $locked;
        });
    }

    /**
     * Whether this person took part in the count. Staff who did may not sign it off.
     *
     * @param  iterable<int, StockCountLine>  $lines  Counted lines of the session.
     */
    public function tookPartInCount(User $actor, StockCountSession $session, iterable $lines): bool
    {
        if (in_array((int) $actor->id, [(int) $session->started_by, (int) $session->submitted_by], true)) {
            return true;
        }
        foreach ($lines as $line) {
            if ((int) $line->counted_by === (int) $actor->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Active stock branches that hold goods and have not had an approved count within
     * {@see OverdueDays}. A branch never counted is measured from the day it was created.
     *
     * @return Collection<int, CompanyBranch>
     */
    public function overdueBranches(): Collection
    {
        $cutoff = now()->subDays(self::OverdueDays);
        $lastApproved = StockCountSession::query()
            ->where('status', StockCountSession::StatusApproved)
            ->groupBy('branch_id')
            ->selectRaw('branch_id, MAX(decided_at) AS last_counted_at')
            ->pluck('last_counted_at', 'branch_id');
        $holdingStock = BranchProductStock::query()
            ->where('quantity', '>', 0)
            ->distinct()
            ->pluck('branch_id');

        return CompanyBranch::query()
            ->active()
            ->where('is_stock_hub', true)
            ->whereIn('id', $holdingStock)
            ->orderBy('code')
            ->get()
            ->filter(function (CompanyBranch $branch) use ($lastApproved, $cutoff): bool {
                $lastCounted = $lastApproved[$branch->id] ?? $branch->created_at;

                return $lastCounted === null || Carbon::parse($lastCounted)->lt($cutoff);
            })
            ->values();
    }

    /**
     * Posts one counted difference. A shortage leaves the earliest-expiring lots first, like any
     * stock-out. A surplus has no paperwork, so it joins the batch that goes out next and is
     * costed at the product's cost price; a product with no usable batch gets a lot with none.
     */
    private function postVariance(User $actor, StockCountSession $session, StockCountLine $line, int $variance, string $reference): void
    {
        $adjustment = [
            'product_id' => (int) $line->product_id,
            'branch_id' => (int) $session->branch_id,
            'quantity' => abs($variance),
            'reason_code' => 'correction',
            'reason' => 'Stock count #'.$session->id,
            'reference' => $reference,
        ];
        if ($variance < 0) {
            $this->stock->adjust($actor, [...$adjustment, 'kind' => BranchOperations::KindProductOut]);

            return;
        }
        $product = Product::query()->findOrFail($line->product_id);
        $this->stock->stockIn($actor, [...$adjustment, ...$this->lots->nextBatchSummary($product)]);
    }

    /**
     * Refuses staff who took part in the count; the owner may decide a count they took part in.
     *
     * @param  iterable<int, StockCountLine>  $lines
     * @return bool True when the owner decided their own count.
     */
    private function assertMayDecide(User $actor, StockCountSession $session, iterable $lines): bool
    {
        if (! $this->tookPartInCount($actor, $session, $lines)) {
            return false;
        }
        app(SelfApproval::class)->finishOwnStep($actor, 'count', __('purchasing.err_second_person'));

        return true;
    }

    /**
     * Whether this person may approve or reject the count: anyone with the right who did not
     * take part, and the owner even when they did.
     *
     * @param  iterable<int, StockCountLine>  $lines  Counted lines of the session.
     */
    public function canDecide(User $actor, StockCountSession $session, iterable $lines): bool
    {
        return $actor->canAct('inventory', 'approve')
            && (! $this->tookPartInCount($actor, $session, $lines) || app(SelfApproval::class)->ownerFinishesAlone($actor));
    }

    private function lockSession(StockCountSession $session): StockCountSession
    {
        return StockCountSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
    }

    private function assertMay(User $actor, string $action): void
    {
        $this->branches->assertCompanyHandler($actor);
        abort_unless($actor->canAct('inventory', $action), 403, __('portal.err_staff_action_denied'));
    }
}
