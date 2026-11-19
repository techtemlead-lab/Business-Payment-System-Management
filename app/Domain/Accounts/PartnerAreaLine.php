<?php

namespace App\Domain\Accounts;

use App\Models\User;

/**
 * The area line on a partner's dashboard card, written by the level the partner works at: a division
 * or district partner shows only that name; an upazila partner "upazila, district, division"; a union
 * partner "union, upazila, district, division".
 */
final class PartnerAreaLine
{
    public function for(User $user): string
    {
        $profile = PartnerProfileLock::profileOf($user);

        $names = [
            'union' => $profile['union'] ?? null,
            'upazila' => $profile['upazila'] ?? null,
            'district' => $user->district,
            'division' => $user->division,
        ];

        // On a Bangla screen the place names are Bangla too (CR-14); a name that has no match is kept as saved.
        return self::format(app()->getLocale() === 'bn' ? BanglaPlaceNames::translate($names) : $names);
    }

    /**
     * @param  array{union: mixed, upazila: mixed, district: mixed, division: mixed}  $names
     */
    public static function format(array $names): string
    {
        $clean = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' && trim($value) !== '—' ? trim($value) : null;
        $union = $clean($names['union']);
        $upazila = $clean($names['upazila']);
        $district = $clean($names['district']);
        $division = $clean($names['division']);

        // The deepest level the partner has filled in decides how much is written.
        if ($union !== null) {
            return implode(', ', array_filter([$union, $upazila, $district, $division]));
        }
        if ($upazila !== null) {
            return implode(', ', array_filter([$upazila, $district, $division]));
        }

        return $district ?? $division ?? '';
    }
}
