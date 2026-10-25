<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionWallet;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PaymentAccounts
{
    /** @return list<string> */
    public static function channels(): array
    {
        return ['Bank', 'bKash', 'Nagad', 'Rocket'];
    }

    /** @return list<array<string, mixed>> */
    public function listing(User $actor, bool $collectionOnly = false, bool $revealSecrets = true): array
    {
        abort_unless($actor->status === 'active', 403);
        abort_unless($collectionOnly || $actor->allows('finance'), 403);

        return DB::table('payment_accounts')->when($collectionOnly, fn ($query) => $query->where('is_active', true)->where('collects_payments', true))
            ->orderBy('label')->get()->map(fn (object $account): array => $this->display($account, $revealSecrets))->all();
    }

    public function mayReceiveCollectionAccounts(User $actor): bool
    {
        if ($actor->status !== 'active') {
            return false;
        }

        return in_array($actor->role, ['dealer', 'sub_dealer'], true)
            || $actor->allows('finance')
            || $actor->allows('orders');
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, array $data, ?int $id = null): int
    {
        abort_unless($actor->allows('finance'), 403);
        $data = Validator::make($data, ['label' => 'required|string|max:120', 'channel' => 'required|in:'.implode(',', self::channels()),
            'holder' => 'required|string|max:150', 'account_number' => ($id ? 'nullable' : 'required').'|string|max:120',
            'details' => 'nullable|string|max:1500', 'collects_payments' => 'required|boolean', 'pays_out' => 'required|boolean',
            'is_active' => 'required|boolean', 'version' => $id ? 'required|integer|min:1' : 'nullable', 'reason' => 'required|string|min:5|max:1000'])->validate();

        return DB::transaction(function () use ($actor, $data, $id): int {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->allows('finance'), 403);
            $old = $id ? DB::table('payment_accounts')->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && ! $old, 404);
            if ($old && $old->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_pay_acct_stale')]);
            }
            $record = collect($data)->only(['label', 'channel', 'holder', 'collects_payments', 'pays_out', 'is_active'])->all();
            $record['account_number'] = ! empty($data['account_number']) ? encrypt(trim($data['account_number'])) : $old->account_number;
            $record['details'] = encrypt($data['details'] ?? '');
            $record['updated_by'] = $actor->id;
            $record['version'] = $old ? $old->version + 1 : 1;
            $record['updated_at'] = now();
            if ($old) {
                DB::table('payment_accounts')->where('id', $id)->update($record);
            } else {
                $id = DB::table('payment_accounts')->insertGetId([...$record, 'created_by' => $actor->id, 'created_at' => now()]);
            }
            Audit::record($actor->id, 'payment_account.saved', (string) $id, ['reason' => $data['reason'], 'version' => $record['version'],
                'before' => $old ? encrypt((array) $old) : null, 'after' => encrypt([...$record, 'id' => $id])]);

            return $id;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function collection(?int $id, string $channel): ?array
    {
        if ($id === null && ! DB::table('payment_accounts')->where('is_active', true)->where('collects_payments', true)->exists()) {
            return null;
        }
        $account = DB::table('payment_accounts')->where('id', $id)->where('is_active', true)->where('collects_payments', true)->where('channel', $channel)->first();
        if (! $account) {
            throw ValidationException::withMessages(['payment_account_id' => __('portal.err_pay_acct_collection')]);
        }

        return $this->display($account, true);
    }

    public function assertPayout(int $id): object
    {
        $account = DB::table('payment_accounts')->where('id', $id)->where('is_active', true)->where('pays_out', true)->first();
        if (! $account) {
            throw ValidationException::withMessages(['payment_account_id' => __('portal.err_pay_acct_payout')]);
        }

        return $account;
    }

    public function recordReference(User $actor, string $reference, string $source, ?int $accountId): void
    {
        CommissionWallet::lock();
        $account = null;
        if ($accountId !== null) {
            $account = $this->assertPayout($accountId);
        } elseif (DB::table('payment_accounts')->where('is_active', true)->where('pays_out', true)->exists()) {
            throw ValidationException::withMessages(['payment_account_id' => __('portal.err_pay_acct_required')]);
        }
        $reference = Str::upper(trim($reference));
        if (DB::table('manual_payment_references')->where('reference', $reference)->exists()
            || DB::table('withdrawals')->whereRaw('UPPER(TRIM(payment_reference)) = ?', [$reference])->exists()) {
            throw ValidationException::withMessages(['payment_reference' => __('portal.err_pay_ref_duplicate')]);
        }
        DB::table('manual_payment_references')->insert(['reference' => $reference, 'source' => $source,
            'payment_account_id' => $accountId, 'actor_id' => $actor->id, 'created_at' => now()]);
        Audit::record($actor->id, 'manual_payment.account_captured', $source, ['payment_account_id' => $accountId,
            'payment_account_version' => $account?->version, 'account_snapshot' => $account ? encrypt($this->display($account)) : null]);
    }

    /** @return list<array<string, mixed>> */
    public function history(User $actor, int $id): array
    {
        abort_unless($actor->allows('finance'), 403);
        abort_unless(DB::table('payment_accounts')->where('id', $id)->exists(), 404);
        Audit::record($actor->id, 'payment_account.history_viewed', (string) $id);

        return DB::table('audit_events')->where('action', 'payment_account.saved')->where('subject', (string) $id)->orderByDesc('id')->get()
            ->map(function ($event): array {
                $metadata = json_decode($event->metadata, true, flags: JSON_THROW_ON_ERROR);

                return ['version' => $metadata['version'], 'reason' => $metadata['reason'], 'recorded_at' => $event->created_at,
                    ...(! empty($metadata['after']) ? $this->display((object) decrypt($metadata['after']), true) : []), 'id' => $event->id];
            })->all();
    }

    /** @return array<string, mixed> */
    private function display(object $account, bool $revealSecrets = true): array
    {
        $number = decrypt($account->account_number);
        $details = $account->details ? decrypt($account->details) : '';
        $masked = $this->maskAccountNumber((string) $number);

        return [...collect($account)->only(['id', 'label', 'channel', 'holder', 'collects_payments', 'pays_out', 'is_active', 'version'])->all(),
            'account_number' => $revealSecrets ? $number : $masked,
            'account_number_masked' => $masked,
            'details' => $revealSecrets ? $details : '',
            'secrets_revealed' => $revealSecrets];
    }

    private function maskAccountNumber(string $number): string
    {
        $trimmed = preg_replace('/\s+/', '', $number) ?: '';
        if (strlen($trimmed) <= 4) {
            return str_repeat('•', max(4, strlen($trimmed)));
        }

        return str_repeat('•', max(4, strlen($trimmed) - 4)).substr($trimmed, -4);
    }
}
