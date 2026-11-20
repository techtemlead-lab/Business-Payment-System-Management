<?php

namespace App\Domain\Accounts;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The "My profile" page of the owner, MD, Chairman and office staff: what is shown, and the saves behind its cards.
 *
 * Personal details live in the account profile JSON. The most personal ones (date of birth, the two addresses, the
 * emergency contact name and phone) are encrypted at rest by the ProtectedProfile cast, and the National ID number
 * by the encrypted `users.nid` column, so nothing new is invented here. Audit lines name the fields that changed,
 * never their values.
 */
final class OfficeProfile
{
    public const BloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    public const Genders = ['male', 'female', 'other'];

    /** Profile key stamped when the office has verified the NID of a staff member; from then on only the office changes it. */
    public const NidVerifiedKey = 'nid_verified_at';

    public static function applies(User $user): bool
    {
        return $user->owner() || $user->staff();
    }

    /** The NID is locked for this person: staff whose NID the office has verified. The owner is never locked. */
    public static function nidLocked(User $user): bool
    {
        if ($user->owner() || trim((string) $user->nid) === '') {
            return false;
        }

        return filled(is_array($user->profile) ? ($user->profile[self::NidVerifiedKey] ?? null) : null);
    }

    /** Only the last four digits: "•••••• 1234". */
    public static function maskedNid(?string $nid): ?string
    {
        $digits = trim((string) $nid);
        if ($digits === '') {
            return null;
        }

        return str_repeat('•', max(0, strlen($digits) - 4)).substr($digits, -4);
    }

    /**
     * Everything the page shows, for the signed-in person.
     *
     * @return array<string, mixed>
     */
    public function payload(User $user): array
    {
        $profile = is_array($user->profile) ? $user->profile : [];
        $device = DB::table('known_sign_in_devices')->where('user_id', $user->id)->orderByDesc('last_seen_at')->first(['device', 'last_seen_at']);
        $isOwner = $user->owner();
        $branches = $isOwner ? [] : $user->branchAssignments()->with('branch')->get()
            ->map(fn ($assignment): ?string => $assignment->branch?->summary()['display_name'] ?? null)->filter()->values()->all();
        $identityDocument = DB::table('kyc_documents')->where('user_id', $user->id)->where('kind', 'nid_front')->where('uploaded_by', $user->id)->exists();

        return [
            'is_owner' => $isOwner,
            'contact' => [
                'name' => $user->name,
                'name_bn' => $profile['name_bn'] ?? '',
                'email' => $user->email,
                'phone' => $user->phone ?? '',
                'phone2' => $profile['phone2'] ?? '',
            ],
            'personal' => [
                'date_of_birth' => $profile['date_of_birth'] ?? '',
                'blood_group' => $profile['blood_group'] ?? '',
                'gender' => $profile['gender'] ?? '',
                'present_address' => $profile['present_address'] ?? '',
                'permanent_address' => $profile['permanent_address'] ?? '',
                'same_as_present' => filled($profile['present_address'] ?? null) && ($profile['present_address'] ?? null) === ($profile['permanent_address'] ?? null),
                'emergency_contact_name' => $profile['emergency_contact_name'] ?? '',
                'emergency_contact_relation' => $profile['emergency_contact_relation'] ?? '',
                'emergency_contact_phone' => $profile['emergency_contact_phone'] ?? '',
            ],
            'identity' => [
                'nid_masked' => self::maskedNid($user->nid),
                'locked' => self::nidLocked($user),
                'verified' => filled($profile[self::NidVerifiedKey] ?? null),
                'photo_sent' => $identityDocument,
            ],
            'work' => [
                'display_title' => $profile['display_title'] ?? '',
                'role' => $user->role,
                'branches' => $branches,
                'employee_code' => $profile['employee_code'] ?? ($user->dealer_code ?: null),
                'designation' => $profile['designation'] ?? null,
                'joined_on' => $user->created_at?->toDateString(),
            ],
            'safety' => [
                'last_sign_in_at' => $device?->last_seen_at ? (string) $device->last_seen_at : null,
                'last_sign_in_device' => $device?->device,
            ],
            'options' => ['blood_groups' => self::BloodGroups, 'genders' => self::Genders],
        ];
    }

    /**
     * Writes plain profile keys and returns the names that really changed. Blank text clears a key.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public function saveProfileKeys(User $user, array $values): array
    {
        $profile = is_array($user->profile) ? $user->profile : [];
        $changed = [];
        foreach ($values as $key => $value) {
            $new = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
            if (($profile[$key] ?? null) !== $new) {
                $changed[] = $key;
            }
            if ($new === null) {
                unset($profile[$key]);
            } else {
                $profile[$key] = $new;
            }
        }
        if ($changed !== []) {
            $user->forceFill(['profile' => $profile])->save();
        }

        return $changed;
    }

    /**
     * Saves the NID number and/or photo. Staff whose NID is verified are refused; the owner is free.
     *
     * @return list<string> the fields that changed (`nid`, `nid_photo`)
     */
    public function saveIdentity(User $user, ?string $nidTyped, ?UploadedFile $photo): array
    {
        if (self::nidLocked($user)) {
            throw ValidationException::withMessages(['nid' => __('office_profile.err_nid_locked')]);
        }
        $changed = [];
        if ($nidTyped !== null && trim($nidTyped) !== '') {
            $clean = PartnerKycDocuments::cleanNid($nidTyped);
            if ($clean === null) {
                throw ValidationException::withMessages(['nid' => __('office_profile.err_nid_bad')]);
            }
            if ($clean !== trim((string) $user->nid)) {
                $user->nid = $clean;
                $changed[] = 'nid';
                // A new number waits for the office again.
                $profile = is_array($user->profile) ? $user->profile : [];
                unset($profile[self::NidVerifiedKey]);
                $user->profile = $profile;
                $user->save();
            }
        }
        if ($photo !== null) {
            $this->storeNidPhoto($user, $photo);
            $changed[] = 'nid_photo';
        }

        return $changed;
    }

    /** The photo goes to the private disk like every other ID paper; a new one replaces the person's earlier one. */
    private function storeNidPhoto(User $user, UploadedFile $photo): void
    {
        $old = DB::table('kyc_documents')->where('user_id', $user->id)->where('kind', 'nid_front')->where('uploaded_by', $user->id)->get(['id', 'disk_path']);
        $id = (string) Str::uuid();
        $extension = strtolower((string) ($photo->guessExtension() ?: $photo->getClientOriginalExtension() ?: 'bin'));
        $path = $photo->storeAs('kyc-documents/'.$user->id, $id.'.'.$extension, 'local');
        DB::table('kyc_documents')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'kind' => 'nid_front',
            'disk_path' => $path,
            'original_name' => Str::limit((string) $photo->getClientOriginalName(), 255, ''),
            'mime' => (string) ($photo->getMimeType() ?: 'application/octet-stream'),
            'size_bytes' => (int) $photo->getSize(),
            'uploaded_by' => $user->id,
            'review_status' => $user->owner() ? 'approved' : 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($old as $row) {
            Storage::disk('local')->delete((string) $row->disk_path);
            DB::table('kyc_documents')->where('id', $row->id)->delete();
        }
    }

    /** One audit line naming the fields that changed; values are never written. */
    public static function audit(User $user, string $action, array $changed): void
    {
        if ($changed !== []) {
            Audit::record($user->id, $action, (string) $user->id, ['changed_fields' => array_values($changed)]);
        }
    }
}
