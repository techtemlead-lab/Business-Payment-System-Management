<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionWallet;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinanceSettings
{
    /**
     * Payouts the system can approve on its own once the rules are met.
     * Car and bike rewards are deliberately absent: they always need a person.
     */
    public const AutoApprovalItems = ['salary', 'royalty', 'rank_incentive'];

    /**
     * Auto-approval: on by default while the company is small. When the owner
     * turns it off, each item can still be left automatic or sent for approval.
     *
     * @return array{enabled: bool, items: array<string, bool>, version: int}
     */
    public function autoApproval(): array
    {
        $row = DB::table('business_settings')->where('key', 'auto_approval')->first();
        $stored = $row ? json_decode($row->value, true, 512, JSON_THROW_ON_ERROR) : [];
        $items = [];
        foreach (self::AutoApprovalItems as $item) {
            $items[$item] = (bool) ($stored['items'][$item] ?? true);
        }

        return ['enabled' => (bool) ($stored['enabled'] ?? true), 'items' => $items, 'version' => $row?->version ?? 0];
    }

    /** True when this payout should go through without anyone approving it. */
    public function isAutoApproved(string $item): bool
    {
        if (! in_array($item, self::AutoApprovalItems, true)) {
            return false;
        }
        $setting = $this->autoApproval();

        return $setting['enabled'] || $setting['items'][$item];
    }

    /** @param array<string, mixed> $items */
    public function saveAutoApproval(User $actor, bool $enabled, array $items, int $version, string $reason): void
    {
        DB::transaction(function () use ($actor, $enabled, $items, $version, $reason): void {
            CommissionWallet::lock();
            // Auto-approval pays money out with no second person, so only an owner may change it.
            $fresh = $actor->fresh();
            abort_unless($fresh->owner() && $fresh->canAct('finance', 'edit'), 403);
            $before = $this->autoApproval();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_fin_wd_stale')]);
            }
            $clean = [];
            foreach (self::AutoApprovalItems as $item) {
                $clean[$item] = filter_var($items[$item] ?? false, FILTER_VALIDATE_BOOLEAN);
            }
            $after = ['enabled' => $enabled, 'items' => $clean];
            $stored = DB::table('business_settings')->where('key', 'auto_approval')->first();
            DB::table('business_settings')->updateOrInsert(['key' => 'auto_approval'], [
                'value' => json_encode($after, JSON_THROW_ON_ERROR), 'version' => $version + 1,
                'updated_by' => $actor->id, 'created_at' => $stored?->created_at ?? now(), 'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'finance.auto_approval_changed', 'auto_approval', [
                'before' => ['enabled' => $before['enabled'], 'items' => $before['items']], 'after' => $after, 'reason' => $reason,
            ]);
        }, 3);
    }

    /**
     * Identity papers that count as "approved ID papers" for the first withdrawal.
     * KYC documents have no separate approval step: only office staff allowed to
     * handle identity papers can upload them, after checking the originals, so a
     * National ID (front) or birth certificate on file is treated as approved.
     */
    public const ApprovedIdKinds = ['nid_front', 'birth_certificate'];

    /**
     * "Approved ID papers needed before the first withdrawal". Off by default.
     *
     * @return array{enabled: bool, version: int}
     */
    public function kycBeforeFirstWithdrawal(): array
    {
        $row = DB::table('business_settings')->where('key', 'kyc_before_first_withdrawal')->first();
        $stored = $row ? json_decode($row->value, true, 512, JSON_THROW_ON_ERROR) : [];

        return ['enabled' => (bool) ($stored['enabled'] ?? false), 'version' => $row?->version ?? 0];
    }

    /**
     * True when the setting is on, the partner has never had a withdrawal approved
     * or paid, and no approved ID paper is on file yet.
     */
    public function needsKycBeforeWithdrawal(User $partner): bool
    {
        if (! in_array($partner->role, ['dealer', 'sub_dealer'], true) || ! $this->kycBeforeFirstWithdrawal()['enabled']) {
            return false;
        }
        if (DB::table('withdrawals')->where('user_id', $partner->id)->whereIn('status', ['approved', 'paid'])->exists()) {
            return false;
        }

        return ! DB::table('kyc_documents')->where('user_id', $partner->id)->whereIn('kind', self::ApprovedIdKinds)->exists();
    }

    public function saveKycBeforeFirstWithdrawal(User $actor, bool $enabled, int $version, string $reason): void
    {
        DB::transaction(function () use ($actor, $enabled, $version, $reason): void {
            CommissionWallet::lock();
            $fresh = $actor->fresh();
            abort_unless($fresh->owner(), 403);
            $before = $this->kycBeforeFirstWithdrawal();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_fin_wd_stale')]);
            }
            $after = ['enabled' => $enabled];
            $stored = DB::table('business_settings')->where('key', 'kyc_before_first_withdrawal')->first();
            DB::table('business_settings')->updateOrInsert(['key' => 'kyc_before_first_withdrawal'], [
                'value' => json_encode($after, JSON_THROW_ON_ERROR), 'version' => $version + 1,
                'updated_by' => $actor->id, 'created_at' => $stored?->created_at ?? now(), 'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'finance.kyc_before_first_withdrawal_changed', 'kyc_before_first_withdrawal', [
                'before' => ['enabled' => $before['enabled']], 'after' => $after, 'reason' => $reason,
            ]);
        }, 3);
    }

    /** @return array{amount: string, version: int} */
    public function withdrawalMinimum(): array
    {
        $row = DB::table('business_settings')->where('key', 'withdrawal_minimum')->first();

        return ['amount' => $row ? json_decode($row->value, true, 512, JSON_THROW_ON_ERROR)['amount'] : (string) config('madina.withdrawal_minimum'), 'version' => $row?->version ?? 0];
    }

    public function saveMinimum(User $actor, string $amount, int $version, string $reason): void
    {
        DB::transaction(function () use ($actor, $amount, $version, $reason): void {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->canAct('finance', 'edit'), 403);
            $before = $this->withdrawalMinimum();
            $stored = DB::table('business_settings')->where('key', 'withdrawal_minimum')->first();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_fin_wd_stale')]);
            }
            $cents = Money::cents($amount);
            if ($cents < 1 || $cents > 9999999999) {
                throw ValidationException::withMessages(['amount' => __('portal.err_fin_wd_min')]);
            }
            $after = ['amount' => Money::decimal($cents)];
            DB::table('business_settings')->updateOrInsert(['key' => 'withdrawal_minimum'], [
                'value' => json_encode($after, JSON_THROW_ON_ERROR), 'version' => $version + 1,
                'updated_by' => $actor->id, 'created_at' => $stored?->created_at ?? now(), 'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'withdrawal.minimum_changed', 'withdrawal_minimum', ['before' => $before, 'after' => $after, 'reason' => $reason]);
        }, 3);
    }
}
