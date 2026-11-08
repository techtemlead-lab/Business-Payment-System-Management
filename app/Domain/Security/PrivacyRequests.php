<?php

namespace App\Domain\Security;

use App\Domain\Clinic\PrescriptionAccess;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Partners ask for a copy of their data or for it to be deleted; the owners work through the
 * requests by hand. A row carries the account and the request only, never a patient's record.
 * Nothing is exported or deleted automatically.
 */
final class PrivacyRequests
{
    public const PerPage = 50;

    public static function canResolve(User $user): bool
    {
        return $user->status === 'active' && $user->owner();
    }

    public function submit(User $partner, string $kind): PrivacyRequest
    {
        abort_unless(PrescriptionAccess::isPartner($partner) && $partner->status === 'active', 403);

        $request = DB::transaction(function () use ($partner, $kind): PrivacyRequest {
            User::query()->whereKey($partner->id)->lockForUpdate()->firstOrFail();
            $alreadyWaiting = PrivacyRequest::query()->where('user_id', $partner->id)->where('kind', $kind)->whereIn('status', PrivacyRequest::PendingStatuses)->exists();
            if ($alreadyWaiting) {
                throw ValidationException::withMessages(['kind' => __('prescriber_public.privacy_err_already_open')]);
            }
            $created = PrivacyRequest::query()->create(['user_id' => $partner->id, 'kind' => $kind]);
            Audit::record($partner->id, 'privacy.request_submitted', (string) $created->id, ['kind' => $kind]);

            return $created;
        });
        Audit::notifyAdminsAndStaff([], __('prescriber_public.privacy_notice_new_title'), __('prescriber_public.privacy_notice_new_body', [
            'name' => $partner->name,
            'kind' => __('prescriber_public.privacy_kind_'.$kind),
        ]));

        return $request;
    }

    public function resolve(User $owner, PrivacyRequest $privacyRequest, string $status, ?string $note): void
    {
        abort_unless(self::canResolve($owner), 403);

        $resolved = DB::transaction(function () use ($owner, $privacyRequest, $status, $note): PrivacyRequest {
            $locked = PrivacyRequest::query()->whereKey($privacyRequest->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, PrivacyRequest::PendingStatuses, true)) {
                throw ValidationException::withMessages(['status' => __('prescriber_public.privacy_err_closed')]);
            }
            $closing = $status !== PrivacyRequest::StatusInProgress;
            $locked->forceFill([
                'status' => $status,
                'resolved_by' => $closing ? $owner->id : null,
                'resolved_at' => $closing ? now() : null,
                'resolution_note' => filled($note) ? trim((string) $note) : null,
            ])->save();
            Audit::record($owner->id, 'privacy.request_resolved', (string) $locked->id, ['kind' => $locked->kind, 'status' => $status]);

            return $locked;
        });

        $partner = User::query()->find($resolved->user_id, ['id', 'locale']);
        if ($partner !== null) {
            Audit::notice(
                $partner->id,
                __('prescriber_public.privacy_notice_update_title', [], $partner->locale),
                __('prescriber_public.privacy_notice_update_body', [
                    'kind' => __('prescriber_public.privacy_kind_'.$resolved->kind, [], $partner->locale),
                    'status' => __('prescriber_public.privacy_status_'.$status, [], $partner->locale),
                ], $partner->locale),
            );
        }
    }

    /**
     * The owner's queue, waiting requests first. Only the columns below are read, so patient
     * records cannot reach this page.
     *
     * @return LengthAwarePaginator<int, array{id: int, kind: string, status: string, partner: string|null, partner_code: string|null, partner_role: string|null, resolution_note: string|null, created_at: string|null, resolved_at: string|null}>
     */
    public function queue(): LengthAwarePaginator
    {
        return PrivacyRequest::query()
            ->with('user:id,name,dealer_code,role')
            ->orderByRaw('CASE WHEN status IN (?, ?) THEN 0 ELSE 1 END', PrivacyRequest::PendingStatuses)
            ->orderByDesc('id')
            ->paginate(self::PerPage, ['id', 'user_id', 'kind', 'status', 'resolution_note', 'created_at', 'resolved_at'], 'privacy_page')
            ->withQueryString()
            ->through(fn (PrivacyRequest $request): array => [
                'id' => $request->id,
                'kind' => $request->kind,
                'status' => $request->status,
                'partner' => $request->user?->name,
                'partner_code' => $request->user?->dealer_code,
                'partner_role' => $request->user?->role,
                'resolution_note' => $request->resolution_note,
                'created_at' => $request->created_at?->toIso8601String(),
                'resolved_at' => $request->resolved_at?->toIso8601String(),
            ]);
    }

    /**
     * A partner's own requests, newest first.
     *
     * @return list<array{id: int, kind: string, status: string, resolution_note: string|null, created_at: string|null}>
     */
    public function ownRequests(User $partner): array
    {
        return PrivacyRequest::query()
            ->where('user_id', $partner->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'kind', 'status', 'resolution_note', 'created_at'])
            ->map(fn (PrivacyRequest $request): array => [
                'id' => $request->id,
                'kind' => $request->kind,
                'status' => $request->status,
                'resolution_note' => $request->resolution_note,
                'created_at' => $request->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
