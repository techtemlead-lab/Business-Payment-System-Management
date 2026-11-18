<?php

namespace App\Domain\Security;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Who may finish a step they started themselves.
 *
 * The company has a single owner and nobody ranks above them, so the owner finishes their own
 * step directly ({@see self::finishOwnStep()}), including checking their own opening balances
 * before money features are turned on. Staff never do: someone else must take the next step
 * after them.
 *
 * Callers run this inside their locked transaction and refuse the person receiving the
 * money before they get here, because that block is never waived.
 */
final class SelfApproval
{
    public const MinimumReasonLength = 5;

    /**
     * Whether this person is the owner, who finishes their own steps without a second person.
     * Re-read so a role or status changed a moment ago counts. The MD and Chairman keep their
     * present rights until the owner decides otherwise ({@see OwnerExemption}).
     */
    public function ownerFinishesAlone(User $actor): bool
    {
        return OwnerExemption::applies($actor->fresh());
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
}
