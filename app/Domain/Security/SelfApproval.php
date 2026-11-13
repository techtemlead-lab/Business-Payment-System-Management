<?php

namespace App\Domain\Security;

use App\Domain\Staff\PartnerStaffLink;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Who may finish a step they started themselves.
 *
 * The company has a single owner and nobody ranks above them, so the owner finishes their own
 * step directly ({@see self::finishOwnStep()}). Staff never do: someone else must take the
 * next step after them.
 *
 * Opening balances keep the older two-person rule ({@see self::authorizeAlone()}): the owner
 * checks their own figures alone only when no other active person could.
 *
 * Callers run this inside their locked transaction and refuse the person receiving the
 * money before they get here, because that block is never waived.
 */
final class SelfApproval
{
    public const MinimumReasonLength = 5;

    /**
     * The accounts nobody ranks above. The MD and Chairman keep their present rights until
     * the owner decides otherwise.
     */
    private const OwnerRoles = ['super_admin'];

    /** Whether this person is the owner, who finishes their own steps without a second person. */
    public function ownerFinishesAlone(User $actor): bool
    {
        $current = $actor->fresh();

        return $current !== null
            && $current->status === 'active'
            && in_array($current->role, self::OwnerRoles, true);
    }

    /**
     * Lets the owner finish a step they started, or refuses anyone else. The caller marks the
     * step as done alone in the activity log.
     *
     * @throws ValidationException
     */
    public function finishOwnStep(User $actor, string $field, string $refusal): void
    {
        if (! $this->ownerFinishesAlone($actor)) {
            throw ValidationException::withMessages([$field => $refusal]);
        }
    }

    /**
     * Everyone else who could approve a money step.
     *
     * @param  array<int, int|string|null>  $cannotDoStep  People already ruled out, such as the one who entered it.
     * @return list<int>
     */
    public function otherEligible(User $actor, array $cannotDoStep = []): array
    {
        $excluded = array_map('intval', array_filter([$actor->id, ...$cannotDoStep], fn (int|string|null $id): bool => $id !== null));

        return User::query()
            ->where('status', 'active')
            ->whereNotIn('id', $excluded)
            ->where(fn ($people) => $people->staffAccounts()->orWhere(fn ($linked) => $linked->whereIn('role', ['dealer', 'sub_dealer'])->whereNotNull('linked_staff_role')))
            ->get()
            ->filter(fn (User $person): bool => $this->approvesMoney($person))
            ->map(fn (User $person): int => (int) $person->id)
            ->values()
            ->all();
    }

    /**
     * The two-person rule's one way out: whether the owner may check their own opening
     * balances because no other active person could.
     *
     * @param  array<int, int|string|null>  $cannotDoStep
     */
    public function isAvailable(User $actor, array $cannotDoStep = []): bool
    {
        return $this->ownerFinishesAlone($actor) && $this->otherEligible($actor, $cannotDoStep) === [];
    }

    /**
     * Opening balances only: lets the step go ahead with one person, or refuses it. The owner
     * confirms they are doing it alone. A reason is the caller's to record and is never demanded
     * here: only the owner finishes alone, and that account is never made to write one
     * (ReasonFields::neverForced()).
     *
     * @param  array<int, int|string|null>  $cannotDoStep
     *
     * @throws ValidationException
     */
    public function authorizeAlone(User $actor, string $field, string $refusal, bool $confirmed, string $reason, array $cannotDoStep = []): void
    {
        if (! $this->isAvailable($actor, $cannotDoStep)) {
            throw ValidationException::withMessages([$field => $refusal]);
        }
        if (! $confirmed) {
            throw ValidationException::withMessages([$field => __('self_approval.err_confirm')]);
        }
    }

    /**
     * Whether this person holds the finance approval right. A partner's linked staff desk only
     * counts in a staff session, which is not this request, so its capabilities are read directly.
     */
    private function approvesMoney(User $person): bool
    {
        if ($person->hasLinkedStaffAccess() && in_array('finance', app(PartnerStaffLink::class)->linkedCapabilities($person), true)) {
            return true;
        }

        return $person->canAct('finance', 'approve');
    }
}
