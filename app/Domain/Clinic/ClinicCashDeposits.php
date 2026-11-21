<?php

namespace App\Domain\Clinic;

use App\Domain\Commissions\CommissionWallet;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Carrying the clinic's takings to the company's bank or main cash. Clinic sales debit
 * `clinic_cash`; until now nothing moved it on, so it only grew. A deposit posts debit
 * `manual_collection_clearing` (money received, waiting to be matched with the bank statement,
 * the same account other money taken in by hand uses), credit `clinic_cash`.
 *
 * It moves money between the company's own places, so one person records it; it can never take
 * out more than the clinic holds, and the same request key twice records it once.
 */
final class ClinicCashDeposits
{
    public const ReceivedAccount = 'manual_collection_clearing';

    public static function canRecord(User $user): bool
    {
        return $user->status === 'active' && ($user->allows('finance') || PrescriptionAccess::isOwnerDoctor($user));
    }

    /** What the clinic holds now, in cents. */
    public function balance(): int
    {
        $totals = DB::table('journal_lines')->where('account', ClinicDispensing::ClinicCashAccount)->selectRaw('COALESCE(SUM(debit), 0) AS debit, COALESCE(SUM(credit), 0) AS credit')->first();

        return Money::cents((string) $totals->debit) - Money::cents((string) $totals->credit);
    }

    /**
     * @param  array{amount: string, deposited_on: string, reference?: ?string, note: string, request_key: string}  $data
     */
    public function record(User $actor, array $data): int
    {
        abort_unless(self::canRecord($actor), 403);
        CommissionWallet::requireActive();
        $reference = trim((string) ($data['reference'] ?? ''));
        $reference = $reference === '' ? null : Str::upper($reference);

        return DB::transaction(function () use ($actor, $data, $reference): int {
            // Exclusive, so two deposits at once cannot both take the same clinic cash.
            CommissionWallet::lock();
            abort_unless(self::canRecord($actor->fresh()), 403);
            $old = DB::table('clinic_cash_deposits')->where('request_key', $data['request_key'])->first();
            if ($old) {
                abort_if(Money::cents((string) $old->amount) !== Money::cents($data['amount']) || (int) $old->created_by !== (int) $actor->id, 409, __('prescription.err_deposit_repeat_key'));

                return (int) $old->id;
            }
            $amount = Money::cents($data['amount']);
            if ($amount <= 0 || $amount > $this->balance()) {
                throw ValidationException::withMessages(['amount' => __('prescription.err_deposit_too_much')]);
            }
            if ($reference !== null && DB::table('clinic_cash_deposits')->whereRaw('UPPER(reference) = ?', [$reference])->exists()) {
                throw ValidationException::withMessages(['reference' => __('prescription.err_deposit_reference_used')]);
            }
            $id = DB::table('clinic_cash_deposits')->insertGetId([
                'amount' => Money::decimal($amount), 'deposited_on' => $data['deposited_on'], 'reference' => $reference, 'note' => trim($data['note']),
                'request_key' => $data['request_key'], 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $entry = (string) Str::uuid();
            DB::table('journal_entries')->insert(['id' => $entry, 'event_key' => 'clinic.cash_deposit.'.$id, 'description' => mb_substr(__('prescription.jr_cash_deposit', ['reference' => $reference ?? '—']), 0, 255), 'actor_id' => $actor->id, 'created_at' => now()]);
            DB::table('journal_lines')->insert([
                ['entry_id' => $entry, 'user_id' => null, 'account' => self::ReceivedAccount, 'debit' => Money::decimal($amount), 'credit' => '0.00'],
                ['entry_id' => $entry, 'user_id' => null, 'account' => ClinicDispensing::ClinicCashAccount, 'debit' => '0.00', 'credit' => Money::decimal($amount)],
            ]);
            Audit::record($actor->id, 'clinic.cash_deposited', (string) $id, ['amount' => Money::decimal($amount), 'deposited_on' => $data['deposited_on'], 'reference' => $reference]);

            return $id;
        }, 3);
    }

    /**
     * The clinic cash panel: what it holds and the latest deposits.
     *
     * @return array{balance: string, deposits: list<array<string, mixed>>}
     */
    public function page(): array
    {
        return [
            'balance' => Money::decimal($this->balance()),
            'deposits' => DB::table('clinic_cash_deposits as d')->join('users as u', 'u.id', '=', 'd.created_by')->orderByDesc('d.id')->limit(10)
                ->get(['d.id', 'd.amount', 'd.deposited_on', 'd.reference', 'd.note', 'u.name as recorded_by'])
                ->map(fn (object $row): array => (array) $row)->all(),
        ];
    }
}
