<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionWallet;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Support\TakaText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CR-16: the money sizes at or above which an item waits for the owner. Below them a staff
 * member with the right permission finishes the item (with a second staff member where the
 * flow asks for one); at or above them only an owner may approve. The owner sets each amount
 * on the finance settings page, and every change is audit-logged.
 *
 * "Owner" here is {@see User::owner()}: the Super Admin, and the MD or Chairman when they have
 * the flow's own access, the same people the owner-only fund and the limits page already trust.
 */
final class OwnerApprovalLimits
{
    public const Withdrawal = 'withdrawal';

    public const Refund = 'refund';

    public const FundSpend = 'fund_spend';

    /** @var array<string, string> */
    public const Defaults = [
        self::Withdrawal => '100000.00',
        self::Refund => '50000.00',
        self::FundSpend => '100000.00',
    ];

    /** The withdrawal key is the existing large-withdrawal limit, so its saved amount carries over. */
    private const Keys = [
        self::Withdrawal => 'withdrawal_approval_limit',
        self::Refund => 'refund_owner_limit',
        self::FundSpend => 'fund_spend_owner_limit',
    ];

    private const Events = [
        self::Withdrawal => 'finance.withdrawal_approval_limit_changed',
        self::Refund => 'finance.refund_owner_limit_changed',
        self::FundSpend => 'finance.fund_spend_owner_limit_changed',
    ];

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_keys(self::Defaults);
    }

    /** @return array{amount: string, version: int} */
    public function current(string $kind): array
    {
        $row = DB::table('business_settings')->where('key', $this->key($kind))->first();
        $stored = $row ? json_decode($row->value, true, 512, JSON_THROW_ON_ERROR) : [];

        return ['amount' => (string) ($stored['amount'] ?? self::Defaults[$kind]), 'version' => (int) ($row->version ?? 0)];
    }

    /**
     * Every limit, read in one query.
     *
     * @return array<string, array{amount: string, version: int, default: string}>
     */
    public function all(): array
    {
        $rows = DB::table('business_settings')->whereIn('key', self::Keys)->get()->keyBy('key');
        $limits = [];
        foreach (self::Keys as $kind => $key) {
            $row = $rows->get($key);
            $stored = $row ? json_decode($row->value, true, 512, JSON_THROW_ON_ERROR) : [];
            $limits[$kind] = ['amount' => (string) ($stored['amount'] ?? self::Defaults[$kind]), 'version' => (int) ($row->version ?? 0), 'default' => self::Defaults[$kind]];
        }

        return $limits;
    }

    /** Whether an item of this size must be approved by an owner. */
    public function needsOwner(string $kind, string $amount): bool
    {
        return Money::cents($amount) >= Money::cents($this->current($kind)['amount']);
    }

    /** Whether this person may approve an item at or above a limit. */
    public static function ownerDecides(?User $user): bool
    {
        return $user !== null && $user->status === 'active' && $user->owner();
    }

    /**
     * Refuses a staff member who is not an owner when the item is at or above the limit.
     *
     * @throws ValidationException
     */
    public function refuseUnlessOwnerMayApprove(User $actor, string $kind, string $amount, string $field): void
    {
        if ($this->needsOwner($kind, $amount) && ! self::ownerDecides($actor->fresh())) {
            throw ValidationException::withMessages([$field => __('self_approval.err_owner_needed', [
                'amount' => TakaText::amount($this->current($kind)['amount']),
            ])]);
        }
    }

    public function save(User $actor, string $kind, string $amount, int $version, string $reason): void
    {
        DB::transaction(function () use ($actor, $kind, $amount, $version, $reason): void {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->owner(), 403);
            $before = $this->current($kind);
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_fin_wd_stale')]);
            }
            $cents = Money::cents($amount);
            if ($cents < 1) {
                throw ValidationException::withMessages(['amount' => __('accounting.err_limit_positive')]);
            }
            $after = ['amount' => Money::decimal($cents)];
            $stored = DB::table('business_settings')->where('key', $this->key($kind))->first();
            DB::table('business_settings')->updateOrInsert(['key' => $this->key($kind)], [
                'value' => json_encode($after, JSON_THROW_ON_ERROR), 'version' => $version + 1,
                'updated_by' => $actor->id, 'created_at' => $stored->created_at ?? now(), 'updated_at' => now(),
            ]);
            Audit::record($actor->id, self::Events[$kind], $this->key($kind), [
                'before' => ['amount' => $before['amount']], 'after' => $after, 'reason' => $reason,
            ]);
        }, 3);
    }

    private function key(string $kind): string
    {
        abort_unless(isset(self::Keys[$kind]), 422);

        return self::Keys[$kind];
    }
}
