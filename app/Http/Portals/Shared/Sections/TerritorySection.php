<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Geography\ManagementReferral;
use App\Domain\Geography\PlaceCodeCatalog;
use App\Models\AreaDealerAssignment;
use App\Models\PlaceCode;
use App\Models\User;
use App\Support\Money;

/**
 * Territories: area dealer seats and the place-code catalog.
 */
final class TerritorySection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        return match ($context->section) {
            'area-dealers' => $this->areaDealers(),
            'place-codes' => $this->placeCodes($context),
            default => [],
        };
    }

    /**
     * Each dealer's place seat, with the divisions and place codes ready for assignment.
     *
     * @return array<string, mixed>
     */
    private function areaDealers(): array
    {
        $props = [];
        $props['assignments'] = $this->dealerPlaceSeats();
        $catalogDivisions = PlaceCode::query()
            ->where('level', PlaceCode::LevelDivision)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name');
        $dealerDivisions = collect($props['assignments'])->pluck('division');
        $divisions = [];
        $seen = [];
        foreach ($catalogDivisions->merge($dealerDivisions) as $name) {
            $label = trim((string) $name);
            $key = mb_strtolower($label);
            if ($label === '' || isset($seen[$key]) || PlaceCode::isPlaceholderLabel($label)) {
                continue;
            }
            $seen[$key] = true;
            $divisions[] = $label;
        }
        natcasesort($divisions);
        $props['divisions'] = array_values($divisions);
        $props['place_codes'] = PlaceCode::query()
            ->whereIn('level', PlaceCode::AssignmentLevels)
            ->where('is_active', true)
            ->whereNotNull('code')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'level', 'parent_id', 'is_active'])
            ->filter(fn (PlaceCode $place): bool => $place->isReadyForAssignment())
            ->values();
        $props['dealers_list'] = [];

        return $props;
    }

    /**
     * One place-code level and the management referral settings.
     *
     * @return array<string, mixed>
     */
    private function placeCodes(SectionContext $context): array
    {
        $request = $context->request;
        $user = $context->user;
        $props = [];
        $level = (string) $request->query('level', PlaceCode::LevelDivision);
        if (! in_array($level, PlaceCode::Levels, true)) {
            $level = PlaceCode::LevelDivision;
        }
        $query = $request->query('q');
        $props = array_merge($props, app(PlaceCodeCatalog::class)->modulePayload(
            $level,
            is_string($query) ? $query : null,
        ));
        $referral = app(ManagementReferral::class)->settings();
        $props['management_referral'] = [
            ...$referral,
            'can_manage' => $user->owner(),
        ];

        return $props;
    }

    /**
     * Each dealer is the area dealer of the place code on their account.
     * One place code has one dealer. Staff and branch managers are not seats.
     *
     * @return list<array<string, mixed>>
     */
    private function dealerPlaceSeats(): array
    {
        $dealers = User::query()
            ->where('role', 'dealer')
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
        $codes = $dealers
            ->map(fn (User $dealer): string => trim((string) (is_array($dealer->profile) ? ($dealer->profile['place_code'] ?? '') : '')))
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->values();
        $places = PlaceCode::query()
            ->whereIn('code', $codes)
            ->get()
            ->keyBy(fn (PlaceCode $place): string => strtoupper($place->code));
        $quotas = AreaDealerAssignment::query()
            ->where('is_active', true)
            ->whereIn('user_id', $dealers->pluck('id'))
            ->get()
            ->keyBy('user_id');

        return $dealers->map(function (User $dealer) use ($places, $quotas): array {
            $profile = is_array($dealer->profile) ? $dealer->profile : [];
            $code = trim((string) ($profile['place_code'] ?? ''));
            $place = $code !== '' ? $places->get(strtoupper($code)) : null;
            $level = $place?->level && in_array($place->level, PlaceCode::AssignmentLevels, true)
                ? $place->level
                : null;
            $assignment = $quotas->get($dealer->id);
            if ($level === null && $assignment && in_array((string) $assignment->level, PlaceCode::AssignmentLevels, true)) {
                $level = (string) $assignment->level;
            }
            if ($level === null && filled($dealer->district)) {
                $level = 'district';
            }
            $storedQuota = $assignment && $place && (int) $assignment->place_code_id === (int) $place->id
                ? (string) $assignment->monthly_quota
                : null;
            $quota = $storedQuota !== null && Money::cents($storedQuota) > 0
                ? $storedQuota
                : $this->standardAreaQuota($level);

            return [
                'id' => $dealer->id,
                'user_id' => $dealer->id,
                'level' => $level ?? '',
                'zone_name' => $place?->name ?? ($code !== '' ? $code : $dealer->name),
                'division' => $dealer->division,
                'district' => $dealer->district,
                'upazila' => $profile['upazila'] ?? null,
                'union' => $profile['union'] ?? null,
                'monthly_quota' => $quota,
                'is_active' => $code !== '' && $place instanceof PlaceCode,
                'created_at' => $dealer->created_at,
                'place_code' => $code !== '' ? [
                    'id' => $place?->id ?? 0,
                    'code' => $place?->code ?? $code,
                    'name' => $place?->name ?? $code,
                    'level' => $place?->level ?? 'district',
                ] : null,
                'user' => [
                    'id' => $dealer->id,
                    'name' => $dealer->name,
                    'dealer_code' => $dealer->dealer_code,
                    'phone' => $dealer->phone,
                    'district' => $dealer->district,
                    'division' => $dealer->division,
                ],
            ];
        })->values()->all();
    }

    private function standardAreaQuota(?string $level): string
    {
        return match ($level) {
            'division' => '50000.00',
            'district' => '40000.00',
            'upazila' => '20000.00',
            'union' => '10000.00',
            default => '0.00',
        };
    }
}
