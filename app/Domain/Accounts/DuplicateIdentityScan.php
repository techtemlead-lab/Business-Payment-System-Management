<?php

namespace App\Domain\Accounts;

use App\Support\PhoneIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Finds dealers and sub-dealers who share a phone, an NID or a payout number, or whose
 * upline contains another account of the same person, and lists them for the owner and
 * finance to check.
 *
 * It only writes to identity_review_flags. It never blocks an account, and never touches
 * commissions, wallets or sponsors. NID and payout numbers are stored encrypted, so they
 * are compared here as keyed hashes, and neither the number nor its hash leaves this class
 * except as the opaque match key on a flag.
 */
final class DuplicateIdentityScan
{
    public const KindPhone = 'phone';

    public const KindNid = 'nid';

    public const KindPayout = 'payout';

    public const KindSelfReferral = 'self_referral';

    /** @var list<string> */
    public const Kinds = [self::KindPhone, self::KindNid, self::KindPayout, self::KindSelfReferral];

    /**
     * @return array{found: int, opened: int}
     */
    public function run(): array
    {
        $scannedAt = CarbonImmutable::now();
        $sponsors = [];
        /** @var array<string, array<string, array<int, true>>> $groups */
        $groups = [self::KindPhone => [], self::KindNid => [], self::KindPayout => []];

        $partners = DB::table('users')->whereIn('role', ['dealer', 'sub_dealer'])->orderBy('id')->select(['id', 'phone', 'nid', 'sponsor_id']);
        foreach ($partners->cursor() as $partner) {
            $id = (int) $partner->id;
            $sponsors[$id] = $partner->sponsor_id === null ? null : (int) $partner->sponsor_id;
            $phone = PhoneIdentity::normalize((string) $partner->phone);
            if ($phone !== null) {
                $groups[self::KindPhone][$phone][$id] = true;
            }
            $nid = $this->nidNumber($partner->nid);
            if ($nid !== null) {
                $groups[self::KindNid][$nid][$id] = true;
            }
        }

        $payouts = DB::table('withdrawals')->join('users', 'users.id', '=', 'withdrawals.user_id')
            ->whereIn('users.role', ['dealer', 'sub_dealer'])->orderBy('withdrawals.created_at')
            ->select(['withdrawals.user_id', 'withdrawals.destination']);
        foreach ($payouts->cursor() as $payout) {
            $number = $this->payoutNumber($payout->destination);
            if ($number !== null) {
                $groups[self::KindPayout][$number][(int) $payout->user_id] = true;
            }
        }

        $matches = $this->matches($groups, $sponsors);
        $opened = 0;
        foreach ($matches as $match) {
            $opened += $this->remember($match, $scannedAt) ? 1 : 0;
        }
        // A match that has gone away (say a phone was corrected) leaves the open list by itself.
        DB::table('identity_review_flags')->where('status', 'open')->where('last_seen_at', '<', $scannedAt)
            ->update(['status' => 'resolved', 'updated_at' => $scannedAt]);

        return ['found' => count($matches), 'opened' => $opened];
    }

    /**
     * @param  array<string, array<string, array<int, true>>>  $groups
     * @param  array<int, int|null>  $sponsors
     * @return list<array{kind: string, match_hash: string, user_ids: list<int>, via: list<string>}>
     */
    private function matches(array $groups, array $sponsors): array
    {
        $matches = [];
        /** @var array<string, array{user_ids: list<int>, via: array<string, true>}> $selfReferrals */
        $selfReferrals = [];
        foreach ($groups as $kind => $values) {
            foreach ($values as $value => $members) {
                if (count($members) < 2) {
                    continue;
                }
                $userIds = array_keys($members);
                sort($userIds);
                $matches[] = ['kind' => $kind, 'match_hash' => $this->keyedHash($kind, (string) $value), 'user_ids' => $userIds, 'via' => [$kind]];
                $linked = $this->inOneUpline($userIds, $sponsors);
                if ($linked !== []) {
                    $key = implode(',', $linked);
                    $selfReferrals[$key]['user_ids'] = $linked;
                    $selfReferrals[$key]['via'][$kind] = true;
                }
            }
        }
        foreach ($selfReferrals as $key => $referral) {
            $matches[] = ['kind' => self::KindSelfReferral, 'match_hash' => $this->keyedHash(self::KindSelfReferral, $key), 'user_ids' => $referral['user_ids'], 'via' => array_keys($referral['via'])];
        }

        return $matches;
    }

    /**
     * The accounts of this group that sit above or below another account of the same group.
     *
     * @param  list<int>  $userIds
     * @param  array<int, int|null>  $sponsors
     * @return list<int>
     */
    private function inOneUpline(array $userIds, array $sponsors): array
    {
        $group = array_flip($userIds);
        $linked = [];
        foreach ($userIds as $userId) {
            $visited = [$userId => true];
            $ancestor = $sponsors[$userId] ?? null;
            while ($ancestor !== null && ! isset($visited[$ancestor])) {
                if (isset($group[$ancestor])) {
                    $linked[$userId] = true;
                    $linked[$ancestor] = true;
                }
                $visited[$ancestor] = true;
                $ancestor = $sponsors[$ancestor] ?? null;
            }
        }
        $result = array_keys($linked);
        sort($result);

        return $result;
    }

    /**
     * @param  array{kind: string, match_hash: string, user_ids: list<int>, via: list<string>}  $match
     * @return bool True when this match now needs checking and did not before.
     */
    private function remember(array $match, CarbonImmutable $scannedAt): bool
    {
        $existing = DB::table('identity_review_flags')->where('kind', $match['kind'])->where('match_hash', $match['match_hash'])->first();
        $values = ['user_ids' => json_encode($match['user_ids'], JSON_THROW_ON_ERROR), 'via' => json_encode($match['via'], JSON_THROW_ON_ERROR), 'last_seen_at' => $scannedAt, 'updated_at' => $scannedAt];
        if ($existing === null) {
            DB::table('identity_review_flags')->insert([...$values, 'kind' => $match['kind'], 'match_hash' => $match['match_hash'], 'status' => 'open', 'first_seen_at' => $scannedAt, 'created_at' => $scannedAt]);

            return true;
        }
        // Someone cleared these exact accounts already. A new account joining the match needs a fresh look.
        $sameAccounts = json_decode((string) $existing->user_ids, true, flags: JSON_THROW_ON_ERROR) === $match['user_ids'];
        $staysCleared = $existing->status === 'cleared' && $sameAccounts;
        DB::table('identity_review_flags')->where('id', $existing->id)->update([...$values, 'status' => $staysCleared ? 'cleared' : 'open']);

        return ! $staysCleared && $existing->status !== 'open';
    }

    private function keyedHash(string $kind, string $value): string
    {
        return hash_hmac('sha256', $kind.'|'.$value, (string) config('app.key'));
    }

    private function nidNumber(mixed $stored): ?string
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }
        try {
            $number = preg_replace('/[^0-9A-Za-z]/', '', Crypt::decryptString($stored)) ?: '';
        } catch (DecryptException) {
            return null;
        }

        return $number !== '' ? strtoupper($number) : null;
    }

    /** "+880 1712-345678" and "01712345678" are the same bKash number. */
    private function payoutNumber(mixed $stored): ?string
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }
        try {
            $destination = decrypt($stored);
        } catch (DecryptException) {
            return null;
        }
        if (! is_string($destination)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $destination) ?: '';
        if (strlen($digits) < 6) {
            return null;
        }

        return PhoneIdentity::normalize($digits) ?? $digits;
    }
}
