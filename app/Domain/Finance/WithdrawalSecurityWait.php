<?php

namespace App\Domain\Finance;

use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A partner who has just changed their phone number or password cannot request a withdrawal for
 * a short while. If someone else got into the account, this gives the real owner of the account
 * time to notice before money leaves. The owner sets the wait in hours in the finance settings;
 * 0 switches it off.
 */
final class WithdrawalSecurityWait
{
    public const SettingKey = 'withdrawal_security_wait';

    public const DefaultHours = 2;

    public const MaximumHours = 720;

    /** @return array{hours: int, version: int} */
    public function settings(): array
    {
        $row = DB::table('business_settings')->where('key', self::SettingKey)->first();
        $stored = $row ? json_decode($row->value, true, 512, JSON_THROW_ON_ERROR) : [];

        return ['hours' => (int) ($stored['hours'] ?? self::DefaultHours), 'version' => (int) ($row->version ?? 0)];
    }

    public function save(User $actor, int $hours, int $version): void
    {
        DB::transaction(function () use ($actor, $hours, $version): void {
            $fresh = $actor->fresh();
            abort_unless($fresh !== null && $fresh->owner() && $fresh->canAct('finance', 'edit'), 403);
            $before = $this->settings();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_fin_wd_stale')]);
            }
            if ($hours < 0 || $hours > self::MaximumHours) {
                throw ValidationException::withMessages(['hours' => __('withdrawal_wait.err_wait_hours', ['max' => (string) self::MaximumHours])]);
            }
            $existing = DB::table('business_settings')->where('key', self::SettingKey)->first();
            DB::table('business_settings')->updateOrInsert(['key' => self::SettingKey], [
                'value' => json_encode(['hours' => $hours], JSON_THROW_ON_ERROR),
                'version' => $version + 1,
                'updated_by' => $actor->id,
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'withdrawal.security_wait_changed', self::SettingKey, [
                'before' => ['hours' => $before['hours']],
                'after' => ['hours' => $hours],
            ]);
        }, 3);
    }

    /** When this partner may withdraw again, or null when nothing is holding them back now. */
    public function opensAt(User $partner): ?CarbonImmutable
    {
        $hours = $this->settings()['hours'];
        $changed = $partner->credentials_changed_at;
        if ($hours <= 0 || $changed === null) {
            return null;
        }
        $opens = CarbonImmutable::parse($changed)->addHours($hours);

        return $opens->isFuture() ? $opens : null;
    }

    /** The reason shown to the partner, with the time the wait ends in Bangladesh time. */
    public function reason(CarbonImmutable $opensAt): string
    {
        return __('withdrawal_wait.wd_wait_reason', ['time' => $this->localTime($opensAt)]);
    }

    /**
     * Refuses a withdrawal request made inside the wait and records the refusal. Call it outside the
     * request's database transaction, so the record is kept.
     *
     * @throws ValidationException
     */
    public function refuseWhileWaiting(User $partner): void
    {
        $opensAt = $this->opensAt($partner);
        if ($opensAt === null) {
            return;
        }
        Audit::record($partner->id, 'withdrawal.refused_security_wait', (string) $partner->id, [
            'opens_at' => $opensAt->utc()->toIso8601String(),
            'hours' => $this->settings()['hours'],
        ]);

        throw ValidationException::withMessages(['amount' => $this->reason($opensAt)]);
    }

    private function localTime(CarbonImmutable $moment): string
    {
        return $moment->timezone('Asia/Dhaka')->locale(app()->getLocale())->isoFormat('D MMM YYYY, h:mm A');
    }
}
