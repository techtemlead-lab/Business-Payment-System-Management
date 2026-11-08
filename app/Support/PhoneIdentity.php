<?php

namespace App\Support;

final class PhoneIdentity
{
    // This same expression is indexed for existing, staff-maintained phone values.
    public const SQL = "CASE WHEN regexp_replace(phone, '[^0-9]', '', 'g') LIKE '00%' THEN substr(regexp_replace(phone, '[^0-9]', '', 'g'), 3) WHEN char_length(regexp_replace(phone, '[^0-9]', '', 'g')) = 11 AND regexp_replace(phone, '[^0-9]', '', 'g') LIKE '01%' THEN '88' || regexp_replace(phone, '[^0-9]', '', 'g') ELSE regexp_replace(phone, '[^0-9]', '', 'g') END";

    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/[\s()+.\-]/u', '', trim($input));
        if (! is_string($digits) || ! preg_match('/^[0-9]{10,17}$/D', $digits)) {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '01')) {
            $digits = '88'.$digits;
        }

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }

    /** Only the last four digits, as "••••1234"; an empty number stays empty. */
    public static function masked(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';

        return $digits !== '' ? '••••'.substr($digits, -4) : '';
    }

    /**
     * For printed prescriptions: the first three and last four digits, as "017****1234". A
     * number too short to hide anything that way shows only its last two digits.
     */
    public static function maskedForPrint(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';
        if (strlen($digits) === 13 && str_starts_with($digits, '8801')) {
            $digits = substr($digits, 2);
        }
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) < 10) {
            return '****'.substr($digits, -2);
        }

        return substr($digits, 0, 3).str_repeat('*', strlen($digits) - 7).substr($digits, -4);
    }

    /**
     * The phone a dealer may see for someone in their own downline (owner decision): the full
     * number for members they referred directly (sponsor_id = viewer) and for themselves, and
     * only the last four digits for everyone deeper.
     */
    public static function forDownlineViewer(?string $phone, int $memberId, ?int $memberSponsorId, int $viewerId): string
    {
        $phone = (string) ($phone ?? '');
        if ($memberId === $viewerId || ($memberSponsorId !== null && $memberSponsorId === $viewerId)) {
            return $phone;
        }

        return self::masked($phone);
    }
}
