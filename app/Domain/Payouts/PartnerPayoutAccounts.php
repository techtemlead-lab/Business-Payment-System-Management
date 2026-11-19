<?php

namespace App\Domain\Payouts;

use App\Domain\Finance\PaymentAccounts;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * V3: the payout accounts a partner keeps in his profile, so the withdrawal dialog picks one instead of retyping.
 * The details are encrypted; every change is audited (without the number) and counts like a phone change for
 * the withdrawal security wait, because a changed destination is exactly what a stranger in the account would do.
 */
final class PartnerPayoutAccounts
{
    public const MaximumAccounts = 10;

    /**
     * @return list<array{id: int, channel: string, label: string, is_default: bool, bank_name: string, branch: string, account_name: string, number: string}>
     */
    public function listFor(User $partner): array
    {
        return DB::table('partner_payout_accounts')->where('user_id', $partner->id)->orderByDesc('is_default')->orderBy('id')->get()
            ->map(fn (object $row): array => $this->present($row))->all();
    }

    /** @return array<string, mixed>|null */
    public function find(User $partner, int $id): ?array
    {
        $row = DB::table('partner_payout_accounts')->where('user_id', $partner->id)->where('id', $id)->first();

        return $row === null ? null : $this->present($row);
    }

    /**
     * The channel and the destination text a withdrawal request carries for a saved account. Office payout keeps
     * comparing this destination with the number it pays (F-verify-01).
     *
     * @return array{channel: string, destination: string}
     */
    public function asWithdrawal(User $partner, int $id): array
    {
        $account = $this->find($partner, $id);
        if ($account === null) {
            throw ValidationException::withMessages(['payout_account_id' => __('leftovers_partner.pa_not_found')]);
        }
        $destination = $account['channel'] === 'Bank'
            ? $account['number'].' ('.implode(', ', array_filter([$account['bank_name'], $account['branch'], $account['account_name']])).')'
            : $account['number'];

        return ['channel' => $account['channel'], 'destination' => $destination];
    }

    /** @param  array<string, mixed>  $input */
    public function save(User $partner, array $input, ?int $id = null): int
    {
        $details = $this->clean($input);
        $channel = (string) $input['channel'];

        return DB::transaction(function () use ($partner, $details, $channel, $id): int {
            // Serialise a partner's changes, so two taps cannot make two defaults.
            User::query()->whereKey($partner->id)->lockForUpdate()->first();
            $count = DB::table('partner_payout_accounts')->where('user_id', $partner->id)->count();
            if ($id === null) {
                if ($count >= self::MaximumAccounts) {
                    throw ValidationException::withMessages(['number' => __('leftovers_partner.pa_too_many', ['max' => (string) self::MaximumAccounts])]);
                }
                $id = (int) DB::table('partner_payout_accounts')->insertGetId([
                    'user_id' => $partner->id, 'channel' => $channel, 'details' => Crypt::encryptString(json_encode($details, JSON_THROW_ON_ERROR)),
                    'is_default' => $count === 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $action = 'payout_account.added';
            } else {
                $changed = DB::table('partner_payout_accounts')->where('user_id', $partner->id)->where('id', $id)
                    ->update(['channel' => $channel, 'details' => Crypt::encryptString(json_encode($details, JSON_THROW_ON_ERROR)), 'updated_at' => now()]);
                abort_if($changed === 0, 404);
                $action = 'payout_account.edited';
            }
            $this->afterChange($partner, $action, $id, $channel);

            return $id;
        });
    }

    public function remove(User $partner, int $id): void
    {
        DB::transaction(function () use ($partner, $id): void {
            User::query()->whereKey($partner->id)->lockForUpdate()->first();
            $row = DB::table('partner_payout_accounts')->where('user_id', $partner->id)->where('id', $id)->first();
            abort_unless($row !== null, 404);
            DB::table('partner_payout_accounts')->where('id', $id)->delete();
            if ($row->is_default) {
                $next = DB::table('partner_payout_accounts')->where('user_id', $partner->id)->orderBy('id')->value('id');
                if ($next !== null) {
                    DB::table('partner_payout_accounts')->where('id', $next)->update(['is_default' => true]);
                }
            }
            $this->afterChange($partner, 'payout_account.removed', $id, (string) $row->channel);
        });
    }

    public function makeDefault(User $partner, int $id): void
    {
        DB::transaction(function () use ($partner, $id): void {
            User::query()->whereKey($partner->id)->lockForUpdate()->first();
            $row = DB::table('partner_payout_accounts')->where('user_id', $partner->id)->where('id', $id)->first();
            abort_unless($row !== null, 404);
            DB::table('partner_payout_accounts')->where('user_id', $partner->id)->update(['is_default' => false]);
            DB::table('partner_payout_accounts')->where('id', $id)->update(['is_default' => true]);
            // Which account is the default does not move money by itself, so it does not start the security wait.
            Audit::record($partner->id, 'payout_account.default_set', (string) $partner->id, ['account' => $id, 'channel' => (string) $row->channel]);
        });
    }

    private function afterChange(User $partner, string $action, int $id, string $channel): void
    {
        DB::table('users')->where('id', $partner->id)->update(['credentials_changed_at' => now()]);
        Audit::record($partner->id, $action, (string) $partner->id, ['account' => $id, 'channel' => $channel]);
        Audit::notice($partner->id, __('leftovers_partner.pa_notice_title'), __('leftovers_partner.pa_notice_body'));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{bank_name: string, branch: string, account_name: string, number: string}
     */
    private function clean(array $input): array
    {
        $channel = (string) ($input['channel'] ?? '');
        if (! in_array($channel, PaymentAccounts::channels(), true)) {
            throw ValidationException::withMessages(['channel' => __('leftovers_partner.pa_bad_channel')]);
        }
        $number = trim(strtr((string) ($input['number'] ?? ''), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']));
        if ($channel === 'Bank') {
            $number = preg_replace('/[\s-]+/', '', $number) ?? '';
            if (preg_match('/^\d{6,30}$/', $number) !== 1) {
                throw ValidationException::withMessages(['number' => __('leftovers_partner.pa_bad_bank_number')]);
            }
            foreach (['bank_name', 'branch', 'account_name'] as $field) {
                if (trim((string) ($input[$field] ?? '')) === '') {
                    throw ValidationException::withMessages([$field => __('leftovers_partner.pa_need_'.$field)]);
                }
            }
        } else {
            $number = preg_replace('/[\s-]+/', '', $number) ?? '';
            if (preg_match('/^(\+?88)?01[3-9]\d{8}$/', $number) !== 1) {
                throw ValidationException::withMessages(['number' => __('leftovers_partner.pa_bad_mobile')]);
            }
        }

        return [
            'bank_name' => $channel === 'Bank' ? trim((string) $input['bank_name']) : '',
            'branch' => $channel === 'Bank' ? trim((string) $input['branch']) : '',
            'account_name' => $channel === 'Bank' ? trim((string) $input['account_name']) : '',
            'number' => $number,
        ];
    }

    /** @return array{id: int, channel: string, label: string, is_default: bool, bank_name: string, branch: string, account_name: string, number: string} */
    private function present(object $row): array
    {
        $details = json_decode(Crypt::decryptString((string) $row->details), true, 512, JSON_THROW_ON_ERROR);
        $channel = (string) $row->channel;
        $number = (string) ($details['number'] ?? '');
        $label = $channel === 'Bank'
            ? trim(($details['bank_name'] ?? '').' · '.$number, ' ·')
            : __('portal.pay_channel_'.strtolower($channel)).' · '.$number;

        return [
            'id' => (int) $row->id, 'channel' => $channel, 'label' => $label, 'is_default' => (bool) $row->is_default,
            'bank_name' => (string) ($details['bank_name'] ?? ''), 'branch' => (string) ($details['branch'] ?? ''),
            'account_name' => (string) ($details['account_name'] ?? ''), 'number' => $number,
        ];
    }
}
