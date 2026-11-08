<?php

namespace App\Domain\Security;

use App\Domain\Staff\PartnerStaffLink;
use App\Models\User;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * Two-person control, with one way out for a company that has nobody else yet: the Super
 * Admin may finish a step alone when no other active person could do that step.
 *
 * Callers run this inside their locked transaction and refuse the person receiving the
 * money before they get here, because that block is never waived.
 */
final class SelfApproval
{
    public const MinimumReasonLength = 5;

    /**
     * Everyone else who could do this step.
     *
     * @param  array<int, int|string|null>  $cannotDoStep  People already ruled out, such as the one who entered it or the payee.
     * @param  (Closure(User): bool)|null  $canDoStep  For a step whose route rule is not a plain module and action.
     * @return list<int>
     */
    public function otherEligible(User $actor, array $cannotDoStep = [], string $module = 'finance', string $action = 'approve', ?Closure $canDoStep = null): array
    {
        $excluded = array_map('intval', array_filter([$actor->id, ...$cannotDoStep], fn (int|string|null $id): bool => $id !== null));

        return User::query()
            ->where('status', 'active')
            ->whereNotIn('id', $excluded)
            ->where(fn ($people) => $people->staffAccounts()->orWhere(fn ($linked) => $linked->whereIn('role', ['dealer', 'sub_dealer'])->whereNotNull('linked_staff_role')))
            ->get()
            ->filter(fn (User $person): bool => $canDoStep !== null ? $canDoStep($person) : $this->holdsRight($person, $module, $action))
            ->map(fn (User $person): int => (int) $person->id)
            ->values()
            ->all();
    }

    /**
     * Whether this person holds the right the route checks. A partner's linked staff desk only
     * counts in a staff session, which is not this request, so its capabilities are read directly.
     */
    public function holdsRight(User $person, string $module, string $action): bool
    {
        if ($person->hasLinkedStaffAccess() && in_array($module, app(PartnerStaffLink::class)->linkedCapabilities($person), true)) {
            return true;
        }

        return $person->canAct($module, $action);
    }

    /**
     * Whether the actor may finish this step alone.
     *
     * @param  array<int, int|string|null>  $cannotDoStep
     * @param  (Closure(User): bool)|null  $canDoStep
     */
    public function isAvailable(User $actor, array $cannotDoStep = [], string $module = 'finance', string $action = 'approve', ?Closure $canDoStep = null): bool
    {
        $current = $actor->fresh();

        return $current !== null
            && $current->status === 'active'
            && $current->role === 'super_admin'
            && $this->otherEligible($current, $cannotDoStep, $module, $action, $canDoStep) === [];
    }

    /**
     * Lets the step go ahead with one person, or refuses it.
     *
     * @param  array<int, int|string|null>  $cannotDoStep
     * @param  (Closure(User): bool)|null  $canDoStep
     *
     * @throws ValidationException
     */
    public function authorizeAlone(
        User $actor,
        string $field,
        string $refusal,
        bool $confirmed,
        string $reason,
        array $cannotDoStep = [],
        string $module = 'finance',
        string $action = 'approve',
        ?Closure $canDoStep = null,
        bool $receiptNeeded = false,
        bool $hasReceipt = false,
    ): void {
        if (! $this->isAvailable($actor, $cannotDoStep, $module, $action, $canDoStep)) {
            throw ValidationException::withMessages([$field => $refusal]);
        }
        if (! $confirmed) {
            throw ValidationException::withMessages([$field => __('self_approval.err_confirm')]);
        }
        if (mb_strlen(trim($reason)) < self::MinimumReasonLength) {
            throw ValidationException::withMessages([$field => __('self_approval.err_reason')]);
        }
        if ($receiptNeeded && ! $hasReceipt) {
            throw ValidationException::withMessages([$field => __('self_approval.err_receipt')]);
        }
    }
}
