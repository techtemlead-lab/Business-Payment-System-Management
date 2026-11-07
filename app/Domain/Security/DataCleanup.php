<?php

namespace App\Domain\Security;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The owner's manual clean-up of old data that is safe to delete.
 *
 * Nothing here runs on a schedule: the owner picks the rows and deletes them. Only three
 * kinds are ever listed or deleted: old login codes (password-reset codes and expired SMS
 * login codes), SMS send records, and closed enquiries. Orders, ledger, commissions,
 * payments and audit records are never touched.
 */
final class DataCleanup
{
    public const KindLoginCodes = 'login_codes';

    public const KindSmsLogs = 'sms_logs';

    public const KindEnquiries = 'closed_enquiries';

    public const Kinds = [self::KindLoginCodes, self::KindSmsLogs, self::KindEnquiries];

    /** Rows shown on one page, and the most the owner may delete at once. */
    public const PerPage = 50;

    public const MaxPerDelete = 500;

    /** Cache key prefix of an SMS login code (see LoginOtpChallenge). */
    private const LoginOtpKey = 'login_otp:';

    public static function canUse(User $user): bool
    {
        return $user->status === 'active' && $user->owner();
    }

    /**
     * The rows the owner can delete, oldest first.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function listing(?string $kind, int $olderThanDays): LengthAwarePaginator
    {
        $rows = DB::query()
            ->fromSub($this->union($kind), 'cleanup_rows')
            ->where('happened_at', '<', $this->cutoff($olderThanDays))
            ->orderBy('happened_at')
            ->orderBy('ref');

        return $rows->paginate(self::PerPage)->withQueryString()->through(fn ($row): array => [
            'ref' => (string) $row->ref,
            'kind' => (string) $row->kind,
            'label' => (string) $row->label,
            'detail' => $row->detail !== null ? (string) $row->detail : null,
            'happened_at' => Carbon::parse($row->happened_at)->toIso8601String(),
        ]);
    }

    /**
     * How many rows of each kind are older than the cut-off.
     *
     * @return array<string, int>
     */
    public function counts(int $olderThanDays): array
    {
        $counts = DB::query()
            ->fromSub($this->union(null), 'cleanup_rows')
            ->where('happened_at', '<', $this->cutoff($olderThanDays))
            ->groupBy('kind')
            ->selectRaw('kind, COUNT(*) AS total')
            ->pluck('total', 'kind');

        return collect(self::Kinds)->mapWithKeys(fn (string $kind): array => [$kind => (int) ($counts[$kind] ?? 0)])->all();
    }

    /**
     * Delete the chosen rows. Each reference is checked again, so only rows of the three
     * allowed kinds that still qualify are deleted. Records an audit entry with the counts.
     *
     * @param  list<string>  $refs
     * @return array<string, int>
     */
    public function delete(User $actor, array $refs): array
    {
        abort_unless(self::canUse($actor), 403);
        $grouped = array_fill_keys(self::Kinds, []);
        foreach (array_unique($refs) as $ref) {
            [$prefix, $id] = array_pad(explode(':', (string) $ref, 2), 2, '');
            match ($prefix) {
                'reset' => $grouped[self::KindLoginCodes][] = ['reset', $id],
                'otp' => $grouped[self::KindLoginCodes][] = ['otp', $id],
                'sms' => $grouped[self::KindSmsLogs][] = ['sms', (int) $id],
                'enq' => $grouped[self::KindEnquiries][] = ['enq', (int) $id],
                default => throw ValidationException::withMessages(['refs' => __('portal.cleanup_err_invalid')]),
            };
        }

        $deleted = DB::transaction(function () use ($grouped): array {
            $resetEmails = array_values(array_map(fn (array $ref): string => (string) $ref[1], array_filter($grouped[self::KindLoginCodes], fn (array $ref): bool => $ref[0] === 'reset')));
            $otpKeys = array_values(array_map(fn (array $ref): string => (string) $ref[1], array_filter($grouped[self::KindLoginCodes], fn (array $ref): bool => $ref[0] === 'otp')));
            $smsIds = array_values(array_map(fn (array $ref): int => (int) $ref[1], $grouped[self::KindSmsLogs]));
            $enquiryIds = array_values(array_map(fn (array $ref): int => (int) $ref[1], $grouped[self::KindEnquiries]));

            $loginCodes = $resetEmails === [] ? 0 : DB::table('password_reset_tokens')->whereIn('email', $resetEmails)->delete();
            if ($otpKeys !== [] && $this->otpTable() !== null) {
                // Only expired SMS login codes; a code someone may still type is never removed.
                $loginCodes += DB::table($this->otpTable())
                    ->whereIn('key', $otpKeys)
                    ->where('key', 'like', $this->otpKeyPattern())
                    ->where('expiration', '<', now()->getTimestamp())
                    ->delete();
            }

            return [
                self::KindLoginCodes => $loginCodes,
                self::KindSmsLogs => $smsIds === [] ? 0 : DB::table('sms_logs')->whereIn('id', $smsIds)->delete(),
                // Only enquiries that are closed; open ones stay.
                self::KindEnquiries => $enquiryIds === [] ? 0 : DB::table('enquiries')->whereIn('id', $enquiryIds)->where('status', 'closed')->delete(),
            ];
        });

        Audit::record($actor->id, 'data_cleanup.deleted', 'data_cleanup', [
            'counts' => $deleted,
            'total' => array_sum($deleted),
            'requested' => count(array_unique($refs)),
        ]);

        return $deleted;
    }

    private function cutoff(int $olderThanDays): Carbon
    {
        return now()->subDays(max(0, $olderThanDays));
    }

    /** The union of every row the owner may delete, with one shape for all kinds. */
    private function union(?string $kind): Builder
    {
        // Kind and detail values are fixed words from this class, written into the SQL as text.
        $parts = [];
        if ($kind === null || $kind === self::KindLoginCodes) {
            $parts[] = DB::table('password_reset_tokens')
                ->whereNotNull('created_at')
                ->selectRaw("CONCAT('reset:', email) AS ref, 'login_codes'::text AS kind, email::text AS label, 'password_reset'::text AS detail, created_at::timestamp AS happened_at");
            if ($this->otpTable() !== null) {
                $parts[] = DB::table($this->otpTable())
                    ->where('key', 'like', $this->otpKeyPattern())
                    ->where('expiration', '<', now()->getTimestamp())
                    ->selectRaw("CONCAT('otp:', \"key\") AS ref, 'login_codes'::text AS kind, SUBSTRING(\"key\" FROM POSITION('login_otp:' IN \"key\"))::text AS label, 'sms_login'::text AS detail, TO_TIMESTAMP(expiration)::timestamp AS happened_at");
            }
        }
        if ($kind === null || $kind === self::KindSmsLogs) {
            $parts[] = DB::table('sms_logs')
                ->selectRaw("CONCAT('sms:', id) AS ref, 'sms_logs'::text AS kind, phone_masked::text AS label, CONCAT(CASE WHEN sent THEN 'sent' ELSE 'failed' END, CASE WHEN provider IS NULL THEN '' ELSE CONCAT(' · ', provider) END) AS detail, created_at::timestamp AS happened_at");
        }
        if ($kind === null || $kind === self::KindEnquiries) {
            $parts[] = DB::table('enquiries')
                ->where('status', 'closed')
                ->selectRaw("CONCAT('enq:', id) AS ref, 'closed_enquiries'::text AS kind, name::text AS label, topic::text AS detail, COALESCE(updated_at, created_at)::timestamp AS happened_at");
        }
        $query = array_shift($parts);
        foreach ($parts as $part) {
            $query->unionAll($part);
        }

        return $query;
    }

    /** The database cache table that holds SMS login codes, when the cache is kept in the database. */
    private function otpTable(): ?string
    {
        $store = (string) config('cache.default');
        if (config('cache.stores.'.$store.'.driver') !== 'database') {
            return null;
        }

        return (string) config('cache.stores.'.$store.'.table', 'cache');
    }

    private function otpKeyPattern(): string
    {
        return addcslashes((string) config('cache.prefix', ''), '%_\\').self::LoginOtpKey.'%';
    }
}
