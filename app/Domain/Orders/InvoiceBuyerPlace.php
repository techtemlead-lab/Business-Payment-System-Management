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
            return $buyer instanceof User ? $this->fromSavedNames($buyer) : ['level' => null, 'address' => null];
        }

        return ['level' => $place->level, 'address' => $this->address($place)];
    }

    /**
     * A partner with no place code on file (the place list was cleared, or the account came from
     * an import) still saved the names of their division, district, upazila and union. The
     * deepest name that is filled in says what kind of dealer they are.
     *
     * @return array{level: ?string, address: ?string}
     */
    private function fromSavedNames(User $buyer): array
    {
        $savedProfile = $buyer->getAttribute('profile');
        $profile = is_array($savedProfile) ? $savedProfile : [];
        $names = [
            PlaceCode::LevelUnion => trim((string) ($profile['union'] ?? '')),
            PlaceCode::LevelUpazila => trim((string) ($profile['upazila'] ?? '')),
            PlaceCode::LevelDistrict => trim((string) $buyer->getAttribute('district')),
            PlaceCode::LevelDivision => trim((string) $buyer->getAttribute('division')),
        ];
        foreach ($names as $level => $name) {
            if ($name === '') {
                continue;
            }
            $upward = in_array($level, [PlaceCode::LevelDivision, PlaceCode::LevelDistrict], true)
                ? [$name]
                : array_values(array_filter($names, static fn (string $each): bool => $each !== ''));

            return ['level' => $level, 'address' => implode(', ', $upward)];
        }

        return ['level' => null, 'address' => null];
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
