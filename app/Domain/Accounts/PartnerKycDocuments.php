<?php

namespace App\Domain\Accounts;

use App\Domain\Finance\FinanceSettings;
use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Notifications\SmsText;
use App\Models\User;
use App\Support\Audit;
use App\Support\ProofFile;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PartnerKycDocuments
{
    /** @var list<string> */
    public const Kinds = [
        'nid_front',
        'nid_back',
        'birth_certificate',
        'agreement',
        'photo',
        'other',
    ];

    /**
     * @return list<array{
     *   id: string,
     *   kind: string,
     *   original_name: string,
     *   mime: string,
     *   size_bytes: int,
     *   note: ?string,
     *   uploaded_by: int,
     *   created_at: string,
     *   review_status: string,
     *   review_note: ?string,
     *   claimed_nid: ?string
     * }>
     */
    public function listFor(User $partner, bool $withClaimedNid = false): array
    {
        return DB::table('kyc_documents')
            ->where('user_id', $partner->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'kind', 'original_name', 'mime', 'size_bytes', 'note', 'uploaded_by', 'created_at', 'review_status', 'review_note', 'claimed_nid'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'kind' => (string) $row->kind,
                'original_name' => (string) $row->original_name,
                'mime' => (string) $row->mime,
                'size_bytes' => (int) $row->size_bytes,
                'note' => $row->note !== null ? (string) $row->note : null,
                'uploaded_by' => (int) $row->uploaded_by,
                'created_at' => (string) $row->created_at,
                'review_status' => (string) $row->review_status,
                'review_note' => $row->review_note !== null ? (string) $row->review_note : null,
                // Only the identity staff list may read a number a partner is still waiting to have verified.
                'claimed_nid' => $withClaimedNid && $row->claimed_nid !== null ? Crypt::decryptString((string) $row->claimed_nid) : null,
            ])
            ->all();
    }

    /**
     * @return array{id: string, kind: string, original_name: string}
     */
    public function store(User $actor, User $partner, string $kind, UploadedFile $file, ?string $note = null, string $reviewStatus = 'approved', ?string $claimedNid = null): array
    {
        if (! in_array($partner->role, ['dealer', 'sub_dealer'], true)) {
            throw ValidationException::withMessages(['user' => __('portal.err_kyc_partners_only')]);
        }
        if (! in_array($kind, self::Kinds, true)) {
            throw ValidationException::withMessages(['kind' => __('portal.err_kyc_kind')]);
        }

        $id = (string) Str::uuid();
        $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin'));
        $path = $file->storeAs('kyc-documents/'.$partner->id, $id.'.'.$extension, 'local');

        try {
            DB::table('kyc_documents')->insert([
                'id' => $id,
                'user_id' => $partner->id,
                'kind' => $kind,
                'disk_path' => $path,
                'original_name' => Str::limit((string) $file->getClientOriginalName(), 255, ''),
                'mime' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
                'size_bytes' => (int) $file->getSize(),
                'uploaded_by' => $actor->id,
                'note' => $note !== null && trim($note) !== '' ? Str::limit(trim($note), 500, '') : null,
                'review_status' => $reviewStatus,
                'claimed_nid' => $claimedNid !== null ? Crypt::encryptString($claimedNid) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        Audit::record($actor->id, 'account.kyc_document_uploaded', (string) $partner->id, [
            'document_id' => $id,
            'kind' => $kind,
            'mime' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
            'size_bytes' => (int) $file->getSize(),
        ]);

        if ($kind === 'photo' && in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $publicRelative = 'partner-photos/'.$partner->id.'/'.$id.'.'.$extension;
            Storage::disk('public')->put($publicRelative, Storage::disk('local')->get($path));
            $url = Storage::disk('public')->url($publicRelative);
            $profile = is_array($partner->profile) ? $partner->profile : [];
            $previousUrl = is_string($profile['photo_url'] ?? null) ? $profile['photo_url'] : null;
            $previousPrefix = Storage::disk('public')->url('partner-photos/'.$partner->id.'/');
            if ($previousUrl !== null && str_starts_with($previousUrl, $previousPrefix)) {
                Storage::disk('public')->delete('partner-photos/'.$partner->id.'/'.basename($previousUrl));
            }
            $profile['photo_url'] = $url;
            $profile['avatar_url'] = $url;
            $partner->forceFill(['profile' => $profile])->save();
        }

        // A paper the partner sent himself needs no "we received your paper" message to himself.
        if ($reviewStatus !== 'approved') {
            return ['id' => $id, 'kind' => $kind, 'original_name' => Str::limit((string) $file->getClientOriginalName(), 255, '')];
        }

        $locale = $partner->preferredLocale();
        $kindKey = in_array($kind, ['nid_front', 'nid_back', 'birth_certificate', 'agreement', 'photo'], true)
            ? 'portal.kyc_kind_'.$kind
            : 'portal.kyc_kind_other';
        $kindLabel = __($kindKey, [], $locale);
        Audit::notice(
            $partner->id,
            __('portal.notice_kyc_uploaded_title', [], $locale),
            __('portal.notice_kyc_uploaded_body', ['kind' => $kindLabel], $locale),
        );
        app(PartnerEventSms::class)->send(
            $partner,
            'account.kyc_uploaded',
            SmsText::make('sms.kyc_document_uploaded', ['kind' => SmsText::make($kindKey)]),
        );

        return [
            'id' => $id,
            'kind' => $kind,
            'original_name' => Str::limit((string) $file->getClientOriginalName(), 255, ''),
        ];
    }

    /** A National ID number is 10, 13 or 17 digits (Bangla digits are accepted). */
    public static function cleanNid(string $typed): ?string
    {
        $digits = strtr(trim($typed), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);

        return preg_match('/^(\d{10}|\d{13}|\d{17})$/', $digits) === 1 ? $digits : null;
    }

    /**
     * R5: where this partner stands with his own National ID. "none" (nothing sent), "pending" (waiting for
     * staff), "rejected" (staff sent it back, with the reason) or "verified". The number itself is never shown here.
     *
     * @return array{status: string, note: ?string}
     */
    public function nidRequestState(User $partner): array
    {
        $latest = DB::table('kyc_documents')->where('user_id', $partner->id)->where('kind', 'nid_front')->where('uploaded_by', $partner->id)
            ->orderByDesc('created_at')->orderByDesc('id')->first(['review_status', 'review_note']);
        if (trim((string) $partner->nid) !== '' || DB::table('kyc_documents')->where('user_id', $partner->id)->whereIn('kind', FinanceSettings::ApprovedIdKinds)->where('review_status', 'approved')->exists()) {
            return ['status' => 'verified', 'note' => null];
        }
        if ($latest === null) {
            return ['status' => 'none', 'note' => null];
        }

        return ['status' => (string) $latest->review_status, 'note' => $latest->review_note !== null ? (string) $latest->review_note : null];
    }

    /**
     * R5 (decision, reversible by the client): a partner who joined with a birth registration adds his own
     * National ID number and photo. It is stored like any ID paper (private disk, never public) but waits as
     * "pending": it does not open the first withdrawal until staff with the identity permission verify it.
     *
     * @return array{id: string}
     */
    public function submitOwnNid(User $partner, string $nidNumber, UploadedFile $photo): array
    {
        if (! in_array($partner->role, ['dealer', 'sub_dealer'], true)) {
            throw ValidationException::withMessages(['nid' => __('portal.err_kyc_partners_only')]);
        }
        $nid = self::cleanNid($nidNumber);
        if ($nid === null) {
            throw ValidationException::withMessages(['nid' => __('leftovers_partner.nid_bad_number')]);
        }
        $state = $this->nidRequestState($partner)['status'];
        if ($state === 'verified') {
            throw ValidationException::withMessages(['nid' => __('leftovers_partner.nid_already_verified')]);
        }
        if ($state === 'pending') {
            throw ValidationException::withMessages(['nid' => __('leftovers_partner.nid_already_waiting')]);
        }

        $stored = $this->store($partner, $partner, 'nid_front', $photo, null, 'pending', $nid);
        Audit::record($partner->id, 'account.nid_submitted_by_partner', (string) $partner->id, ['document_id' => $stored['id']]);
        Audit::notifyStaffFirst(['identity_write'], __('leftovers_partner.office_nid_title'), __('leftovers_partner.office_nid_body', ['name' => $partner->name, 'code' => (string) ($partner->dealer_code ?: $partner->id)]), $partner->id);

        return ['id' => $stored['id']];
    }

    /**
     * Staff verify or send back a partner's own National ID. Verifying writes the number to the account (it is then
     * on file, as if the office had typed it); sending back needs a reason the partner can read.
     */
    public function reviewNid(User $actor, User $partner, string $documentId, bool $approve, ?string $note): void
    {
        DB::transaction(function () use ($actor, $partner, $documentId, $approve, $note): void {
            $row = DB::table('kyc_documents')->where('id', $documentId)->where('user_id', $partner->id)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            if ($row->review_status !== 'pending' || $row->claimed_nid === null) {
                throw ValidationException::withMessages(['document' => __('leftovers_partner.review_not_pending')]);
            }
            if ($approve) {
                $nid = Crypt::decryptString((string) $row->claimed_nid);
                $account = User::query()->whereKey($partner->id)->lockForUpdate()->firstOrFail();
                $account->nid = $nid;
                $account->save();
            } elseif (trim((string) $note) === '') {
                throw ValidationException::withMessages(['note' => __('leftovers_partner.review_reason_needed')]);
            }
            DB::table('kyc_documents')->where('id', $documentId)->update([
                'review_status' => $approve ? 'approved' : 'rejected',
                'claimed_nid' => null,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => $approve ? null : Str::limit(trim((string) $note), 300, ''),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, $approve ? 'account.nid_verified' : 'account.nid_sent_back', (string) $partner->id, ['document_id' => $documentId]);
        });

        $locale = $partner->preferredLocale();
        Audit::notice(
            $partner->id,
            __($approve ? 'leftovers_partner.notice_nid_ok_title' : 'leftovers_partner.notice_nid_back_title', [], $locale),
            __($approve ? 'leftovers_partner.notice_nid_ok_body' : 'leftovers_partner.notice_nid_back_body', ['reason' => trim((string) $note)], $locale),
        );
    }

    /**
     * Sends one ID paper to staff, to open in the browser or to save. Every opening is written to the audit log so the
     * owner can see who looked at a partner's identity documents and when.
     */
    public function download(User $actor, User $partner, string $documentId, Request $request): StreamedResponse
    {
        $row = DB::table('kyc_documents')
            ->where('id', $documentId)
            ->where('user_id', $partner->id)
            ->first();
        abort_unless($row && Storage::disk('local')->exists($row->disk_path), 404);

        Audit::record($actor->id, 'account.kyc_document_viewed', (string) $partner->id, [
            'document_id' => $row->id,
            'kind' => $row->kind,
        ]);

        // A picture or PDF opens in the browser (a pop-up or a new tab on the page); ?download=1 saves it.
        return ProofFile::response($request, $row->disk_path, $row->original_name, ['Content-Type' => (string) $row->mime]);
    }
}
