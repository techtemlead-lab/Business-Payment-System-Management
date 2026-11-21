<?php

namespace App\Domain\Commissions;

use App\Models\PlaceCode;
use Illuminate\Support\Facades\DB;

/**
 * The kind of dealer each partner is (division, district, upazila or union), which sets his one monthly target
 * (MonthlyTargets::dealerTarget). It follows the same order as the partner's appointment line
 * (PartnerAppointment): the widest active area seat first, then the place on the profile. A pourashava is a
 * union-level place, so it counts as a union dealer. Null when the partner has neither.
 *
 * Two queries for any number of partners, so a month close over every dealer stays cheap.
 */
final class DealerKinds
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, string|null> user id => kind
     */
    public function for(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }
        $kinds = array_fill_keys($userIds, null);
        $order = array_flip(MonthlyTargets::DealerKinds);
        $seats = DB::table('area_dealer_assignments as seats')
            ->join('place_codes as places', 'places.id', '=', 'seats.place_code_id')
            ->whereIn('seats.user_id', $userIds)
            ->where('seats.is_active', true)
            ->get(['seats.user_id', 'places.level']);
        foreach ($seats as $seat) {
            $level = (string) $seat->level;
            $userId = (int) $seat->user_id;
            if (isset($order[$level]) && ($kinds[$userId] === null || $order[$level] < $order[$kinds[$userId]])) {
                $kinds[$userId] = $level;
            }
        }
        $withoutSeat = array_keys(array_filter($kinds, fn (?string $kind): bool => $kind === null));
        if ($withoutSeat !== []) {
            $homes = DB::table('users')
                ->join('place_codes as places', DB::raw('places.id::text'), '=', DB::raw("users.profile->>'place_code_id'"))
                ->whereIn('users.id', $withoutSeat)
                ->whereIn('places.level', PlaceCode::AssignmentLevels)
                ->get(['users.id', 'places.level']);
            foreach ($homes as $home) {
                if (isset($order[(string) $home->level])) {
                    $kinds[(int) $home->id] = (string) $home->level;
                }
            }
        }

        return $kinds;
    }

    public function of(int $userId): ?string
    {
        return $this->for([$userId])[$userId] ?? null;
    }

    /**
     * The kind from an appointment line already worked out for a page (PartnerAppointment::for()['kind']), so the
     * page reads nothing more. A pourashava counts as a union.
     */
    public static function fromAppointmentKind(?string $kind): ?string
    {
        if ($kind === 'pourashava') {
            return 'union';
        }

        return in_array($kind, MonthlyTargets::DealerKinds, true) ? $kind : null;
    }
}
