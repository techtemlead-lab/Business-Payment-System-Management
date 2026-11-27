<?php

namespace App\Domain\Payouts;

use App\Domain\Accounts\PartnerProfileLock;
use App\Domain\Inventory\BranchScope;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The office payout list for "Office withdrawal" (cash at the office): on the payout day the partner comes
 * to the office, staff check their ID card, hand over the money net of tax and mark it handed over.
 *
 * Marking uses the same payment path as every manual payout (ManualPayoutService::settle with method
 * "cash"), so the journal entry, the numbered payout receipt, the audit record and the partner's notice
 * are the usual ones. There is no bank or CSV export for this way. Marking twice is harmless: a second
 * mark of a payout already handed over returns the first receipt and posts nothing.
 */
final class OfficePayouts
{
    public function __construct(private readonly ManualPayoutService $payouts, private readonly WithdrawalWeek $week) {}

    /**
     * Office withdrawals still open (waiting for approval or for the cash), and those handed over today.
     *
     * @return list<array<string, mixed>>
     */
    public function list(User $viewer, ?BranchScope $branchScope = null): array
    {
        $todayStart = CarbonImmutable::now(WithdrawalWeek::TimeZone)->startOfDay()->utc();
        $query = DB::table('withdrawals')->join('users', 'users.id', '=', 'withdrawals.user_id')
            ->where('withdrawals.channel', WithdrawalMethods::Office)
            ->where(fn ($where) => $where->whereIn('withdrawals.status', ['requested', 'approved'])
                ->orWhere(fn ($paid) => $paid->where('withdrawals.status', 'paid')->where('withdrawals.paid_at', '>=', $todayStart->toDateTimeString())))
            ->orderByRaw("CASE withdrawals.status WHEN 'approved' THEN 0 WHEN 'requested' THEN 1 ELSE 2 END")
            ->orderBy('withdrawals.created_at')
            ->limit(500);
        $branchScope?->restrictPartners($query);
        $rows = $query->get(['withdrawals.*']);
        $partners = User::query()->whereIn('id', $rows->pluck('user_id')->unique()->all())->get()->keyBy('id');
        $today = CarbonImmutable::now(WithdrawalWeek::TimeZone)->toDateString();
        $weekOn = $this->week->settings()['enabled'];

        return $rows->map(function (object $row) use ($partners, $viewer, $today, $weekOn): array {
            $partner = $partners->get($row->user_id);
            $payoutDate = $this->week->payoutDateFor(CarbonImmutable::parse($row->created_at));

            return [
                'id' => $row->id,
                'user_name' => (string) $partner?->name,
                'dealer_code' => $partner?->dealer_code,
                'phone' => $partner?->phone,
                'amount' => $row->amount,
                'withheld_amount' => $row->withheld_amount,
                'net_amount' => $row->net_amount ?? $row->amount,
                'status' => $row->status,
                'created_at' => $row->created_at,
                'paid_at' => $row->paid_at,
                'payout_date' => $weekOn ? $payoutDate->toDateString() : null,
                'due' => ! $weekOn || $payoutDate->toDateString() <= $today,
                'id_hint' => $partner === null ? null : $this->idHint($partner),
                'blocker' => $partner !== null && in_array($row->status, ['requested', 'approved'], true) ? $this->payouts->unpayableReason($row, $partner) : null,
                'payer_state' => $this->payouts->payerState($row, $viewer),
                'one_step_state' => $this->payouts->oneStepState($row, $viewer),
                'waits_for_owner' => $this->payouts->waitsForOwner($row, $viewer),
            ];
        })->values()->all();
    }

    /**
     * Marks an approved office withdrawal as handed over in cash at the office.
     *
     * @return array{number: string, replayed: bool}
     *
     * @throws ValidationException
     */
    public function handOver(User $actor, string $id, string $paidAt, string $note, ?string $receivedBy = null): array
    {
        $withdrawal = DB::table('withdrawals')->where('id', $id)->first();
        abort_unless($withdrawal !== null, 404);
        if ($withdrawal->channel !== WithdrawalMethods::Office) {
            throw ValidationException::withMessages(['withdrawal' => __('office_withdrawal.err_not_office')]);
        }
        $replay = $this->alreadyHandedOver($id);
        if ($replay !== null) {
            return ['number' => $replay, 'replayed' => true];
        }
        $partner = User::findOrFail($withdrawal->user_id);
        $receiver = trim((string) $receivedBy) !== '' ? trim((string) $receivedBy) : (string) $partner->name;
        try {
            $number = $this->payouts->settle($actor, $id, ['method' => 'cash', 'received_by' => mb_substr($receiver, 0, 100)], null, null, $paidAt, $note);
        } catch (ValidationException $exception) {
            // Two taps at the same moment: the first one paid it, so this one reports the same receipt.
            $replay = $this->alreadyHandedOver($id);
            if ($replay !== null) {
                return ['number' => $replay, 'replayed' => true];
            }
            throw $exception;
        }

        return ['number' => $number, 'replayed' => false];
    }

    /** The receipt number when this withdrawal was already handed over in cash, else null. */
    private function alreadyHandedOver(string $id): ?string
    {
        $paid = DB::table('withdrawals')->where('id', $id)->where('status', 'paid')->exists();
        $receipt = $paid ? DB::table('payout_receipts')->where('withdrawal_id', $id)->first(['number', 'method']) : null;

        return $receipt !== null && $receipt->method === 'cash' ? (string) $receipt->number : null;
    }

    /** What staff check on the partner's ID card: the last digits of the National ID or birth registration. */
    private function idHint(User $partner): string
    {
        $nid = preg_replace('/\D+/', '', (string) $partner->nid) ?? '';
        if ($nid !== '') {
            return __('office_withdrawal.id_hint_nid', ['last' => substr($nid, -4)]);
        }
        $birth = preg_replace('/\D+/', '', (string) (PartnerProfileLock::profileOf($partner)['birth_certificate_number'] ?? '')) ?? '';

        return $birth !== '' ? __('office_withdrawal.id_hint_birth', ['last' => substr($birth, -4)]) : __('office_withdrawal.id_hint_none');
    }

    /**
     * Net cash to hand over across these rows, in cents (for the list's total line).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function cashToHandOver(array $rows): int
    {
        return array_sum(array_map(fn (array $row): int => $row['status'] === 'approved' ? Money::cents((string) $row['net_amount']) : 0, $rows));
    }
}
