<?php

namespace App\Domain\Accounts;

use App\Models\PlaceCode;
use App\Models\ReferralTreePath;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who referred a dealer or sub-dealer, as the office changes it on the account editor.
 *
 * The referrer is the `sponsor_id` link (the 3-generation referral chain); the partner's own
 * refer code is their dealer code and never changes here. A new referrer must be an active
 * dealer or sub-dealer, never the partner, and never someone in the partner's own team below
 * (that would make a loop). An empty code, or the company management code, means "no personal
 * referrer" (the company). Only orders placed after the change follow the new link: every
 * order keeps the referral chain it captured when it was placed or approved.
 */
class PartnerReferrerChange
{
    public function __construct(private readonly PartnerAccountProvisioning $partners) {}

    /**
     * The new referrer a typed code names, or null for "no referrer (company)".
     *
     * @throws ValidationException beside $field when the code cannot be this partner's referrer
     */
    public function referrerForCode(User $partner, ?string $code, string $field = 'sponsor_code'): ?User
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '' || $this->partners->referenceIsManagement($code)) {
            return null;
        }
        $referrer = $this->partners->findSponsor($code);
        if (! $referrer) {
            $known = User::query()
                ->where(fn ($query) => $query->whereIn(DB::raw('UPPER(refer_code)'), PlaceCode::serialForms($code))
                    ->orWhereIn(DB::raw('UPPER(dealer_code)'), PlaceCode::serialForms($code)))
                ->exists();
            throw ValidationException::withMessages([$field => __($known ? 'portal.cmd_val_sponsor_active_dealer' : 'portal.acct_val_reference_missing')]);
        }
        $this->assertCanRefer($partner, $referrer, $field);

        return $referrer;
    }

    /**
     * The new referrer a picked account id names, or null for "no referrer (company)".
     *
     * @throws ValidationException beside $field when that account cannot be this partner's referrer
     */
    public function referrerForId(User $partner, ?int $referrerId, string $field = 'sponsor_id'): ?User
    {
        if (! $referrerId) {
            return null;
        }
        $referrer = User::query()->find($referrerId);
        if (! $referrer || $referrer->id === $partner->id) {
            throw ValidationException::withMessages([$field => __($referrer ? 'portal.cmd_val_self_sponsor' : 'portal.acct_val_reference_missing')]);
        }
        if (! in_array($referrer->role, PartnerAccountProvisioning::SponsorRoles, true) || $referrer->status !== 'active') {
            throw ValidationException::withMessages([$field => __('portal.cmd_val_sponsor_active_dealer')]);
        }
        $this->assertCanRefer($partner, $referrer, $field);

        return $referrer;
    }

    /**
     * What the audit and the editor show for a referrer: id, name and code, or null for the company.
     *
     * @return array{id: int, name: string, code: ?string}|null
     */
    public static function describe(?User $referrer): ?array
    {
        return $referrer ? ['id' => (int) $referrer->id, 'name' => (string) $referrer->name, 'code' => $referrer->dealer_code] : null;
    }

    /** @throws ValidationException when the referrer is the partner or sits in the partner's own team below */
    private function assertCanRefer(User $partner, User $referrer, string $field): void
    {
        if ($referrer->id === $partner->id) {
            throw ValidationException::withMessages([$field => __('portal.cmd_val_self_sponsor')]);
        }
        if (ReferralTreePath::isDescendant($partner->id, $referrer->id) || $this->sponsorChainReaches($referrer, $partner->id)) {
            throw ValidationException::withMessages([$field => __('portal.cmd_val_circular_sponsor')]);
        }
    }

    /** True when walking up from the referrer reaches the partner (checked on users.sponsor_id too, in case the path table lags). */
    private function sponsorChainReaches(User $referrer, int $partnerId): bool
    {
        $seen = [];
        $next = $referrer->sponsor_id ? (int) $referrer->sponsor_id : null;
        while ($next !== null && ! in_array($next, $seen, true)) {
            if ($next === $partnerId) {
                return true;
            }
            $seen[] = $next;
            $sponsorId = User::query()->whereKey($next)->value('sponsor_id');
            $next = $sponsorId ? (int) $sponsorId : null;
        }

        return false;
    }
}
