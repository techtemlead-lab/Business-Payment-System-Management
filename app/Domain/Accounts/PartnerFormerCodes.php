<?php

namespace App\Domain\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Codes a dealer used before moving to another place. Printed cards, posters and shared links
 * still carry them, so they keep finding the dealer until someone else is given that code.
 */
final class PartnerFormerCodes
{
    private const Table = 'partner_former_codes';

    public function remember(User $partner, string $code): void
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return;
        }
        DB::table(self::Table)->upsert(
            [['user_id' => $partner->id, 'code' => $code, 'created_at' => now(), 'updated_at' => now()]],
            ['code'],
            ['user_id', 'updated_at'],
        );
    }

    /**
     * @param  list<string>  $codes  The codes that now belong to someone
     */
    public function forget(array $codes): void
    {
        DB::table(self::Table)->whereIn('code', $this->normalized($codes))->delete();
    }

    /**
     * The partner who last used one of these codes, active or not.
     *
     * @param  list<string>  $codes  One code in its different spellings
     */
    public function holder(array $codes): ?User
    {
        return User::query()
            ->whereIn('id', DB::table(self::Table)->whereIn('code', $this->normalized($codes))->select('user_id'))
            ->whereIn('role', ['dealer', 'sub_dealer'])
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  list<string>  $codes  One code in its different spellings
     */
    public function activeHolder(array $codes): ?User
    {
        $partner = $this->holder($codes);

        return $partner?->status === 'active' ? $partner : null;
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function normalized(array $codes): array
    {
        return array_values(array_unique(array_map(fn (string $code): string => strtoupper(trim($code)), $codes)));
    }
}
