<?php

namespace App\Domain\Accounts;

use App\Models\DealerRank;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Validation\ValidationException;

final class PartnerBenefitControls
{
    public const Ranks = ['mo', 'am', 'sm', 'me'];

    /**
     * @return array{
     *   unconditional_salary: bool,
     *   unconditional_rank_royalty: bool,
     *   forced_royalty_rank: string|null,
     *   unconditional_rank_incentive: bool,
     *   note: string|null,
     *   updated_by: int|null,
     *   updated_at: string|null
     * }
     */
    public static function for(User $user): array
    {
        $controls = is_array($user->profile['benefit_controls'] ?? null) ? $user->profile['benefit_controls'] : [];

        return [
            'unconditional_salary' => (bool) ($controls['unconditional_salary'] ?? false),
            'unconditional_rank_royalty' => (bool) ($controls['unconditional_rank_royalty'] ?? false),
            'forced_royalty_rank' => self::normalizeRank($controls['forced_royalty_rank'] ?? null),
            'unconditional_rank_incentive' => (bool) ($controls['unconditional_rank_incentive'] ?? false),
            'note' => isset($controls['note']) ? (string) $controls['note'] : null,
            'updated_by' => isset($controls['updated_by']) ? (int) $controls['updated_by'] : null,
            'updated_at' => isset($controls['updated_at']) ? (string) $controls['updated_at'] : null,
        ];
    }

    public static function waivesSalaryVolume(User $user): bool
    {
        return self::for($user)['unconditional_salary'];
    }

    public static function waivesRankRoyalty(User $user): bool
    {
        return self::for($user)['unconditional_rank_royalty'];
    }

    public static function forcedRoyaltyRank(User $user): ?string
    {
        $controls = self::for($user);
        if (! $controls['unconditional_rank_royalty']) {
            return null;
        }

        return $controls['forced_royalty_rank'] ?? 'mo';
    }

    public static function waivesRankIncentive(User $user): bool
    {
        return self::for($user)['unconditional_rank_incentive'];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function save(User $actor, User $partner, array $input): array
    {
        if (! in_array($partner->role, ['dealer', 'sub_dealer'], true)) {
            throw ValidationException::withMessages(['user_id' => 'Benefit controls apply only to dealers and sub-dealers.']);
        }

        $rank = self::normalizeRank($input['forced_royalty_rank'] ?? 'mo') ?? 'mo';
        $controls = [
            'unconditional_salary' => (bool) ($input['unconditional_salary'] ?? false),
            'unconditional_rank_royalty' => (bool) ($input['unconditional_rank_royalty'] ?? false),
            'forced_royalty_rank' => $rank,
            'unconditional_rank_incentive' => (bool) ($input['unconditional_rank_incentive'] ?? false),
            'note' => trim((string) ($input['note'] ?? '')) ?: null,
            'updated_by' => $actor->id,
            'updated_at' => now()->toIso8601String(),
        ];

        $profile = is_array($partner->profile) ? $partner->profile : [];
        $profile['benefit_controls'] = $controls;
        $partner->forceFill(['profile' => $profile])->save();
        DealerRank::query()->firstOrCreate(['user_id' => $partner->id]);

        Audit::record($actor->id, 'accounts.benefit_controls_updated', (string) $partner->id, [
            'controls' => $controls,
            'reason' => (string) ($input['reason'] ?? ''),
        ]);

        return $controls;
    }

    private static function normalizeRank(mixed $rank): ?string
    {
        $value = is_string($rank) ? strtolower(trim($rank)) : '';

        return in_array($value, self::Ranks, true) ? $value : null;
    }
}
