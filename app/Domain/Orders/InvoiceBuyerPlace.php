<?php

namespace App\Domain\Orders;

use App\Domain\Geography\PlaceTree;
use App\Models\AreaDealerAssignment;
use App\Models\PlaceCode;
use App\Models\User;

/**
 * The place a partner is printed under on an invoice: what kind of dealer they are
 * (division, district, upazila or union) and their address written from their own
 * place up to the division. A division or district is just its name; an upazila or
 * union carries every name above it too, so "Mithapukur" reads "Mithapukur, Rangpur, Rangpur".
 */
final class InvoiceBuyerPlace
{
    /**
     * @return array{level: ?string, address: ?string}
     */
    public function for(?User $buyer): array
    {
        $place = $buyer instanceof User ? $this->placeOf($buyer) : null;
        if (! $place instanceof PlaceCode) {
            return ['level' => null, 'address' => null];
        }

        return ['level' => $place->level, 'address' => $this->address($place)];
    }

    /** The partner's saved place, then the area they were given, never a guess from a name. */
    private function placeOf(User $buyer): ?PlaceCode
    {
        $savedProfile = $buyer->getAttribute('profile');
        $profile = is_array($savedProfile) ? $savedProfile : [];

        $placeId = PlaceTree::idFrom($profile['place_code_id'] ?? null);
        $place = $placeId !== null ? PlaceCode::query()->find($placeId) : null;
        if (! $place instanceof PlaceCode) {
            $code = trim((string) ($profile['place_code'] ?? ''));
            $place = $code !== '' ? PlaceCode::findByCode($code, false) : null;
        }
        if (! $place instanceof PlaceCode) {
            $assignment = AreaDealerAssignment::query()
                ->where('user_id', $buyer->id)
                ->where('is_active', true)
                ->whereNotNull('place_code_id')
                ->orderBy('id')
                ->first();
            $place = $assignment ? PlaceCode::query()->find($assignment->place_code_id) : null;
        }

        return $place instanceof PlaceCode && $place->level !== PlaceCode::LevelManagement ? $place : null;
    }

    private function address(PlaceCode $place): ?string
    {
        $path = $place->pathNames();
        $names = match ($place->level) {
            PlaceCode::LevelDivision => [$path['division']],
            PlaceCode::LevelDistrict => [$path['district']],
            default => [$path['union'], $path['upazila'], $path['district'], $path['division']],
        };
        $names = array_values(array_filter(
            $names,
            static fn (?string $name): bool => ! PlaceCode::isPlaceholderLabel($name),
        ));

        return $names === [] ? null : implode(', ', $names);
    }
}
