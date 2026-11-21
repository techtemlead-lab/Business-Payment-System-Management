<?php

namespace App\Domain\Payouts;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\OwnerApprovalLimits;
use App\Domain\Security\SelfApproval;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Support\TakaText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Files of approved withdrawals sent to the bank or a mobile wallet (one file = one batch).
 *
 * Downloading a new file marks every withdrawal it lists as "in a bank file" (processing): the
 * batch id and the time go on the withdrawal, so a second download or a later month's file
 * does not list it again and nobody can pay it twice. Pressing the button twice sends the same
 * batch id, so it gives the same file instead of a new one. Downloading an old batch again is
 * a separate, logged action.
 *
 * A withdrawal leaves processing only by being recorded as paid, or by being marked "the bank
 * did not send it", which puts it back on the approved list. While it is in a bank file it
 * cannot be rejected, so money that left the company account is never also put back in the
 * partner's wallet.
 */
final class PayoutBatchFiles
{
    /** Advisory lock taken while a new batch picks its withdrawals, so two batches never share one. */
    private const BatchLockKey = 81416030;

    public function __construct(private readonly PayoutBatch $batches) {}

    /**
     * The file for this batch id: a new batch with every withdrawal ready to send, or the same
     * file again when this batch id was already used with the same month and channel.
     *
     * @return array{batch_id: ?string, rows: list<list<string>>, ids: list<string>, total_net: string, replayed: bool}
     */
    public function export(User $actor, string $batchId, string $month, ?string $channel): array
    {
        abort_unless($actor->allows('finance'), 403);

        return DB::transaction(function () use ($actor, $batchId, $month, $channel): array {
            CommissionWallet::lockShared();
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::BatchLockKey]);
            $existing = DB::table('payout_batches')->where('id', $batchId)->first();
            if ($existing !== null) {
                abort_if($existing->month !== $month || $existing->channel !== $channel, 409, __('payouts.err_batch_key_reused'));
                $file = $this->batches->batchFileRows($batchId);
                Audit::record($actor->id, 'payout_batch.downloaded_again', $batchId, [
                    'replay' => true, 'count' => count($file['rows']), 'total_net' => $file['total_net'], 'withdrawal_ids' => $file['ids'],
                ]);

                return [...$file, 'batch_id' => $batchId, 'replayed' => true];
            }
            $ids = $this->batches->lockReadyIds($month, $channel);
            if ($ids === []) {
                Audit::record($actor->id, 'report.exported', 'payout-batch', ['month' => $month, 'channel' => $channel, 'count' => 0, 'total_net' => '0.00', 'withdrawal_ids' => []]);

                return ['batch_id' => null, 'rows' => [], 'ids' => [], 'total_net' => '0.00', 'replayed' => false];
            }
            $total = 0;
            foreach (DB::table('withdrawals')->whereIn('id', $ids)->get(['amount', 'net_amount']) as $row) {
                $total += Money::cents((string) ($row->net_amount ?? $row->amount));
            }
            DB::table('payout_batches')->insert([
                'id' => $batchId, 'month' => $month, 'channel' => $channel, 'row_count' => count($ids),
                'total_net' => Money::decimal($total), 'created_by' => $actor->id, 'created_at' => now(),
            ]);
            DB::table('withdrawals')->whereIn('id', $ids)->update(['payout_batch_id' => $batchId, 'exported_at' => now(), 'updated_at' => now()]);
            $note = __('payouts.event_in_bank_file', ['batch' => self::shortId($batchId)]);
            DB::table('withdrawal_events')->insert(array_map(fn (string $id): array => [
                'withdrawal_id' => $id, 'actor_id' => $actor->id, 'event' => 'exported', 'note' => $note, 'created_at' => now(),
            ], $ids));
            $file = $this->batches->batchFileRows($batchId);
            Audit::record($actor->id, 'report.exported', 'payout-batch', [
                'batch_id' => $batchId, 'month' => $month, 'channel' => $channel, 'count' => count($file['rows']),
                'total_net' => $file['total_net'], 'withdrawal_ids' => $file['ids'],
            ]);

            return [...$file, 'batch_id' => $batchId, 'replayed' => false];
        }, 3);
    }

    /**
     * Downloads an old batch again on purpose: only its withdrawals still waiting for the bank.
     * Changes nothing and is logged.
     *
     * @return array{batch: object, rows: list<list<string>>, ids: list<string>, total_net: string}
     */
    public function downloadAgain(User $actor, string $batchId): array
    {
        abort_unless($actor->allows('finance'), 403);
        $batch = DB::table('payout_batches')->where('id', $batchId)->first();
        abort_unless($batch !== null, 404);
        $file = $this->batches->batchFileRows($batchId);
        Audit::record($actor->id, 'payout_batch.downloaded_again', $batchId, [
            'count' => count($file['rows']), 'total_net' => $file['total_net'], 'withdrawal_ids' => $file['ids'],
        ]);

        return [...$file, 'batch' => $batch];
    }

    /**
     * The bank or wallet did not send this withdrawal: it leaves its batch and is approved and
     * unpaid again, so finance can send it in a new file, pay it by hand or reject it.
     *
     * Two people, because a wrong mark puts the withdrawal in the next file and it could be paid
     * twice: the first call records the step with a note, a second finance person's call carries
     * it out. The owner finishes alone; at or above the owner's withdrawal limit an owner must take
     * one of the two steps. Returns true when it was carried out.
     */
    public function markNotSent(User $actor, string $withdrawalId, string $note): bool
    {
        abort_unless($actor->allows('finance'), 403);

        return DB::transaction(function () use ($actor, $withdrawalId, $note): bool {
            $w = DB::table('withdrawals')->where('id', $withdrawalId)->lockForUpdate()->first();
            abort_unless($w !== null, 404);
            if ($w->status !== 'approved' || $w->exported_at === null) {
                throw ValidationException::withMessages(['withdrawal' => __('payouts.err_not_in_bank_file')]);
            }
            if ((int) $actor->id === (int) $w->user_id) {
                throw ValidationException::withMessages(['withdrawal' => __('portal.err_wd_self_review')]);
            }
            if ($w->not_sent_requested_by === null) {
                DB::table('withdrawals')->where('id', $withdrawalId)->update(['not_sent_requested_by' => $actor->id, 'not_sent_requested_at' => now(), 'not_sent_note' => $note, 'updated_at' => now()]);
                DB::table('withdrawal_events')->insert(['withdrawal_id' => $withdrawalId, 'actor_id' => $actor->id, 'event' => 'not_sent_requested', 'note' => $note, 'created_at' => now()]);
                Audit::record($actor->id, 'withdrawal.not_sent_requested', $withdrawalId, ['user_id' => $w->user_id, 'amount' => $w->amount, 'batch_id' => $w->payout_batch_id, 'note' => $note]);
                if (! app(SelfApproval::class)->ownerFinishesAlone($actor)) {
                    $title = fn (string $locale): string => __('payouts.notice_not_sent_second_title', [], $locale);
                    $body = fn (string $locale): string => __('payouts.notice_not_sent_second_body', ['amount' => TakaText::amount($w->amount), 'by' => $actor->name], $locale);
                    if ($this->needsOwnerStep($w, $actor)) {
                        Audit::notifyOwners(['finance'], $title, $body, $actor->id);
                    } else {
                        Audit::notifyStaffFirst(['finance'], $title, $body, $actor->id);
                    }

                    return false;
                }
                $requester = $actor;
            } else {
                if ((int) $w->not_sent_requested_by === (int) $actor->id) {
                    throw ValidationException::withMessages(['withdrawal' => __('payouts.err_not_sent_same_person')]);
                }
                $requester = User::find($w->not_sent_requested_by);
                if ($this->needsOwnerStep($w, $requester)) {
                    app(OwnerApprovalLimits::class)->refuseUnlessOwnerMayApprove($actor, OwnerApprovalLimits::Withdrawal, (string) $w->amount, 'withdrawal');
                }
            }
            DB::table('withdrawals')->where('id', $withdrawalId)->update(['payout_batch_id' => null, 'exported_at' => null,
                'not_sent_requested_by' => null, 'not_sent_requested_at' => null, 'not_sent_note' => null, 'updated_at' => now()]);
            DB::table('withdrawal_events')->insert(['withdrawal_id' => $withdrawalId, 'actor_id' => $actor->id, 'event' => 'not_sent', 'note' => $note, 'created_at' => now()]);
            Audit::record($actor->id, 'withdrawal.not_sent', $withdrawalId, [
                'user_id' => $w->user_id, 'amount' => $w->amount, 'batch_id' => $w->payout_batch_id, 'exported_at' => (string) $w->exported_at, 'note' => $note,
                'requested_by' => $requester?->id, 'request_note' => $w->not_sent_note ?? $note,
                ...((int) $requester?->id === (int) $actor->id ? ['self_approved' => true] : []),
            ]);

            return true;
        }, 3);
    }

    /**
     * What this viewer may do about "the bank did not send it": 'record', 'confirm', 'wait_second'
     * (the viewer recorded it), 'wait_owner' (it needs an owner and the viewer is not one), or null.
     */
    public function notSentState(object $withdrawal, User $viewer): ?string
    {
        if ($withdrawal->status !== 'approved' || $withdrawal->exported_at === null || ! $viewer->allows('finance')) {
            return null;
        }
        if ($withdrawal->not_sent_requested_by === null) {
            return 'record';
        }
        if ((int) $withdrawal->not_sent_requested_by === (int) $viewer->id) {
            return 'wait_second';
        }

        return $this->needsOwnerStep($withdrawal, User::find($withdrawal->not_sent_requested_by)) && ! OwnerApprovalLimits::ownerDecides($viewer) ? 'wait_owner' : 'confirm';
    }

    /** At or above the owner's withdrawal limit, an owner must take one of the two steps. */
    private function needsOwnerStep(object $withdrawal, ?User $firstPerson): bool
    {
        return app(OwnerApprovalLimits::class)->needsOwner(OwnerApprovalLimits::Withdrawal, (string) $withdrawal->amount)
            && ! OwnerApprovalLimits::ownerDecides($firstPerson);
    }

    /**
     * The latest files, for the payouts desk: how many rows each listed and how many still wait for the bank.
     *
     * @return list<array{id: string, short_id: string, month: string, channel: ?string, row_count: int, total_net: string, waiting: int, created_at: string, created_by: ?string}>
     */
    public function recent(int $limit = 8): array
    {
        return DB::table('payout_batches as b')->leftJoin('users as u', 'u.id', '=', 'b.created_by')
            ->orderByDesc('b.created_at')->limit($limit)
            ->selectRaw("b.id, b.month, b.channel, b.row_count, b.total_net, b.created_at, u.name AS created_by_name, (SELECT COUNT(*) FROM withdrawals w WHERE w.payout_batch_id = b.id AND w.status = 'approved' AND w.exported_at IS NOT NULL) AS waiting")
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'short_id' => self::shortId((string) $row->id),
                'month' => (string) $row->month,
                'channel' => $row->channel,
                'row_count' => (int) $row->row_count,
                'total_net' => (string) $row->total_net,
                'waiting' => (int) $row->waiting,
                'created_at' => (string) $row->created_at,
                'created_by' => $row->created_by_name,
            ])->all();
    }

    /** The first eight characters of a batch id, as written on the file name and the screens. */
    public static function shortId(string $batchId): string
    {
        return strtoupper(substr($batchId, 0, 8));
    }
}
