<?php

namespace App\Domain\Payouts;

use App\Domain\Finance\PaymentAccounts;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Support\TakaText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money limits the owner sets for payouts and office cash:
 * - the most one withdrawal may ask for, per payment method (mobile money services cap what one
 *   transfer can carry, so a ৳9 crore bKash request can never be paid);
 * - the most cash a partner may promise to bring to the office for an order (above it, pay by bank);
 * - how many days an approved withdrawal may wait before it shows as overdue on the dashboard.
 */
final class PayoutLimits
{
    public const SettingKey = 'payout_limits';

    /** Mobile money default: what one transfer to a personal account can carry. Bank has no limit of its own. */
    public const DefaultPerChannel = ['Bank' => null, 'bKash' => '30000.00', 'Nagad' => '30000.00', 'Rocket' => '30000.00'];

    /** ৳1 crore (client, 2026-11-22; it was ৳2 lakh). The owner changes it in Finance settings. */
    public const DefaultOfficeCash = '10000000.00';

    public const DefaultDueDays = 3;

    public const MaximumDueDays = 60;

    /** The largest amount a decimal(14,2) money column holds. */
    public const ColumnMaximum = '999999999999.99';

    /**
     * @return array{per_channel: array<string, string|null>, office_cash: string, due_days: int, version: int}
     */
    public function settings(): array
    {
        $row = DB::table('business_settings')->where('key', self::SettingKey)->first();
        $stored = $row ? json_decode($row->value, true, 512, JSON_THROW_ON_ERROR) : [];
        $perChannel = [];
        foreach (PaymentAccounts::channels() as $channel) {
            $value = array_key_exists($channel, $stored['per_channel'] ?? []) ? $stored['per_channel'][$channel] : (self::DefaultPerChannel[$channel] ?? null);
            $perChannel[$channel] = $value === null ? null : Money::decimal(Money::cents((string) $value));
        }

        return [
            'per_channel' => $perChannel,
            'office_cash' => Money::decimal(Money::cents((string) ($stored['office_cash'] ?? self::DefaultOfficeCash))),
            'due_days' => (int) ($stored['due_days'] ?? self::DefaultDueDays),
            'version' => (int) ($row->version ?? 0),
        ];
    }

    /** The most one withdrawal by this method may ask for, in cents, or null when only the balance limits it. */
    public function channelLimit(string $channel): ?int
    {
        $limit = $this->settings()['per_channel'][$channel] ?? null;

        return $limit === null ? null : Money::cents($limit);
    }

    /**
     * Refuses a withdrawal that is above its method's limit, with the limit, the partner's balance and
     * what to do instead, in plain words.
     *
     * @throws ValidationException
     */
    public function refuseOverChannelLimit(string $channel, int $amountCents, int $availableCents, string $field = 'amount'): void
    {
        $limit = $this->channelLimit($channel);
        if ($limit === null || $amountCents <= $limit) {
            return;
        }

        throw ValidationException::withMessages([$field => __('payouts.err_channel_limit', [
            'channel' => __('portal.pay_channel_'.strtolower($channel)),
            'limit' => TakaText::amount(Money::decimal($limit)),
            'balance' => TakaText::amount(Money::decimal(max(0, $availableCents))),
        ])]);
    }

    /** The most cash a partner may promise to bring to the office for one order, in cents. */
    public function officeCashLimit(): int
    {
        return Money::cents($this->settings()['office_cash']);
    }

    /**
     * Refuses an office cash payment above the owner's limit.
     *
     * @throws ValidationException
     */
    public function refuseOverOfficeCash(int $amountCents, string $field = 'amount'): void
    {
        $limit = $this->officeCashLimit();
        if ($amountCents <= $limit) {
            return;
        }

        throw ValidationException::withMessages([$field => __('payouts.err_office_cash_limit', [
            'limit' => TakaText::amount(Money::decimal($limit)),
            'amount' => TakaText::withWords(Money::decimal($amountCents)),
        ])]);
    }

    public function dueDays(): int
    {
        return $this->settings()['due_days'];
    }

    /**
     * @param  array{per_channel: array<string, string|null>, office_cash: string, due_days: int}  $values
     */
    public function save(User $actor, array $values, int $version, string $reason): void
    {
        DB::transaction(function () use ($actor, $values, $version, $reason): void {
            $fresh = $actor->fresh();
            abort_unless($fresh !== null && $fresh->owner() && $fresh->canAct('finance', 'edit'), 403);
            $before = $this->settings();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_fin_wd_stale')]);
            }
            $perChannel = [];
            foreach (PaymentAccounts::channels() as $channel) {
                $value = $values['per_channel'][$channel] ?? null;
                $perChannel[$channel] = ($value === null || $value === '') ? null : Money::decimal(Money::cents((string) $value));
            }
            $after = [
                'per_channel' => $perChannel,
                'office_cash' => Money::decimal(Money::cents((string) $values['office_cash'])),
                'due_days' => (int) $values['due_days'],
            ];
            $existing = DB::table('business_settings')->where('key', self::SettingKey)->first();
            DB::table('business_settings')->updateOrInsert(['key' => self::SettingKey], [
                'value' => json_encode($after, JSON_THROW_ON_ERROR),
                'version' => $version + 1,
                'updated_by' => $actor->id,
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'payout.limits_changed', self::SettingKey, [
                'before' => array_diff_key($before, ['version' => true]),
                'after' => $after,
                'reason' => $reason,
            ]);
        }, 3);
    }
}
