<?php

namespace App\Domain\Payouts;

use App\Domain\Finance\PaymentAccounts;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * How a partner may take money out, and which of those ways the owner has switched on.
 *
 * "Office withdrawal" (cash handed over at the office) is the first and default way. bKash, Nagad,
 * Rocket and bank transfers are sent by hand by the office, which then shows the partner a
 * screenshot as proof. The owner switches each way on or off in Finance settings; a way that is off
 * is hidden from the partner and refused by the server, while requests made earlier with it can
 * still be approved and paid. Saved payout accounts are kept whatever the switches say.
 */
final class WithdrawalMethods
{
    public const SettingKey = 'withdrawal_methods';

    /** Cash handed over at the office. */
    public const Office = 'Office';

    /** The text stored as the destination of an office withdrawal; there is no account number. */
    public const OfficeDestination = 'OFFICE';

    /** @return list<string> Every way, the office first. */
    public static function all(): array
    {
        return [self::Office, ...PaymentAccounts::channels()];
    }

    /** True for a way where the office sends the money to an account (bKash, Nagad, Rocket, bank). */
    public static function isOnline(string $channel): bool
    {
        return $channel !== self::Office;
    }

    /**
     * @return array{enabled: array<string, bool>, version: int}
     */
    public function settings(): array
    {
        $row = DB::table('business_settings')->where('key', self::SettingKey)->first();
        $stored = $row ? json_decode((string) $row->value, true, 512, JSON_THROW_ON_ERROR) : null;
        $defaults = (array) config('madina.withdrawal_methods_default', [self::Office]);
        $enabled = [];
        foreach (self::all() as $method) {
            $enabled[$method] = is_array($stored) && array_key_exists($method, $stored['enabled'] ?? [])
                ? (bool) $stored['enabled'][$method]
                : in_array($method, $defaults, true);
        }

        return ['enabled' => $enabled, 'version' => (int) ($row->version ?? 0)];
    }

    /** @return list<string> The ways a partner may choose now, the office first. */
    public function enabled(): array
    {
        return array_keys(array_filter($this->settings()['enabled']));
    }

    public function isEnabled(string $channel): bool
    {
        return $this->settings()['enabled'][$channel] ?? false;
    }

    /**
     * Refuses a new request by a way the owner switched off, in plain words.
     *
     * @throws ValidationException
     */
    public function refuseOff(string $channel, string $field = 'channel'): void
    {
        if ($this->isEnabled($channel)) {
            return;
        }

        throw ValidationException::withMessages([$field => __('office_withdrawal.err_method_off')]);
    }

    /**
     * Saves which ways are on. At least one must stay on, or nobody could take money out.
     *
     * @param  array<string, mixed>  $enabled
     */
    public function save(User $actor, array $enabled, int $version, string $reason): void
    {
        DB::transaction(function () use ($actor, $enabled, $version, $reason): void {
            $fresh = $actor->fresh();
            abort_unless($fresh !== null && $fresh->owner() && $fresh->canAct('finance', 'edit'), 403);
            $before = $this->settings();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_fin_wd_stale')]);
            }
            $after = [];
            foreach (self::all() as $method) {
                $after[$method] = filter_var($enabled[$method] ?? false, FILTER_VALIDATE_BOOLEAN);
            }
            if (! in_array(true, $after, true)) {
                throw ValidationException::withMessages(['enabled' => __('office_withdrawal.err_one_method_on')]);
            }
            $existing = DB::table('business_settings')->where('key', self::SettingKey)->first();
            DB::table('business_settings')->updateOrInsert(['key' => self::SettingKey], [
                'value' => json_encode(['enabled' => $after], JSON_THROW_ON_ERROR),
                'version' => $version + 1,
                'updated_by' => $actor->id,
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'withdrawal.methods_changed', self::SettingKey, [
                'before' => $before['enabled'],
                'after' => $after,
                'reason' => $reason,
            ]);
        }, 3);
    }
}
