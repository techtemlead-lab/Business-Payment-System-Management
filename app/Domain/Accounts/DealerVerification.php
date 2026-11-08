<?php

namespace App\Domain\Accounts;

use App\Domain\Geography\PlaceTree;
use App\Models\PlaceCode;
use App\Models\User;

/**
 * Answers the public "is this a real dealer?" question. Only the fields below ever
 * leave this class, so a phone number or account detail cannot reach the page.
 */
final class DealerVerification
{
    private const CodePattern = '/^[A-Z0-9._-]{2,40}$/D';

    /** The address printed in the partner ID-card QR code. */
    public static function path(string $code): string
    {
        return '/verify-dealer?code='.rawurlencode($code);
    }

    /**
     * Look up a dealer or referral code; null when no partner holds it.
     *
     * @return array{valid: bool, name: string, type: string, place_code: ?string, place_name: ?string, status: string}|null
     */
    public function lookup(string $code): ?array
    {
        $normalized = strtoupper(trim($code));
        if (preg_match(self::CodePattern, $normalized) !== 1) {
            return null;
        }

        $partner = User::query()
            ->whereIn('role', ['dealer', 'sub_dealer'])
            ->where(function ($query) use ($normalized): void {
                $query->whereRaw('UPPER(dealer_code) = ?', [$normalized])
                    ->orWhereRaw('UPPER(refer_code) = ?', [$normalized]);
            })
            ->orderByRaw('CASE WHEN UPPER(dealer_code) = ? THEN 0 ELSE 1 END', [$normalized])
            ->orderBy('id')
            ->first(['id', 'name', 'role', 'status', 'profile']);

        if (! $partner instanceof User) {
            return null;
        }

        $place = $this->placeOf($partner);

        return [
            'valid' => $partner->status === 'active',
            'name' => (string) $partner->name,
            'type' => (string) $partner->role,
            'place_code' => $place?->code,
            'place_name' => $place !== null && ! PlaceCode::isPlaceholderLabel($place->name) ? $place->name : null,
            'status' => $partner->status === 'active' ? 'active' : ($partner->status === 'suspended' ? 'suspended' : 'inactive'),
        ];
    }

    /** The partner's area comes from their saved place code, never from free-typed text. */
    private function placeOf(User $partner): ?PlaceCode
    {
        $savedProfile = $partner->getAttribute('profile');
        $profile = is_array($savedProfile) ? $savedProfile : [];
        $placeId = PlaceTree::idFrom($profile['place_code_id'] ?? null);
        if ($placeId !== null) {
            return PlaceCode::query()->find($placeId, ['id', 'code', 'name']);
        }

        $code = trim((string) ($profile['place_code'] ?? ''));

        return $code !== '' ? PlaceCode::query()->where('code', $code)->first(['id', 'code', 'name']) : null;
    }
}
