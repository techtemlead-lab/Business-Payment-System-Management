<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\AccountPhoneRules;
use App\Domain\Accounts\OfficeProfile;
use App\Domain\Accounts\PartnerProfileLock;
use App\Domain\Security\OwnerConfirmation;
use App\Models\User;
use App\Support\PhoneIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The saves behind the cards of the office "My profile" page. Each card has its own route and its own message.
 * Nothing here can change a person's role, branch, designation or permissions: those are set on the staff edit page.
 */
class OfficeProfileController extends Controller
{
    /** Card 1: name, Bangla name, email and mobile numbers. */
    public function contact(Request $request, OwnerConfirmation $confirmation, AccountPhoneRules $phones): RedirectResponse
    {
        $user = $this->person($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'name_bn' => ['nullable', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:254', Rule::unique('users')->ignore($user->id)],
            'phone' => [$user->owner() ? 'required' : 'nullable', 'string', 'max:30'],
            'phone2' => ['nullable', 'string', 'max:30'],
        ]);
        $phone = filled($data['phone'] ?? null) ? $phones->normalize($data['phone']) : null;
        if ($phone !== null && PhoneIdentity::normalize($phone) !== PhoneIdentity::normalize((string) $user->phone) && $phones->isTaken($phone, $user->id)) {
            throw ValidationException::withMessages(['phone' => __('portal.acct_val_phone_taken')]);
        }
        $phone2 = filled($data['phone2'] ?? null) ? $phones->normalize($data['phone2'], 'phone2') : null;

        $loginChanged = $data['email'] !== $user->email || ($phone ?: null) !== ($user->phone ?: null);
        $changed = [];
        foreach (['name' => $data['name'], 'email' => $data['email']] as $field => $value) {
            if ($value !== $user->{$field}) {
                $changed[] = $field;
            }
        }
        if (($phone ?: null) !== ($user->phone ?: null)) {
            $changed[] = 'phone';
        }
        $profile = PartnerProfileLock::profileOf($user);
        $bnChanged = (trim((string) ($data['name_bn'] ?? '')) ?: null) !== ($profile['name_bn'] ?? null);
        $phone2Changed = $phone2 !== ($profile['phone2'] ?? null);
        if ($changed === [] && ! $bnChanged && ! $phone2Changed) {
            return back()->with('success', __('office_profile.flash_nothing_changed'));
        }

        // The owner's own switch decides what is asked (and it never blocks the Super Admin); staff confirm their
        // password only when they change a way of signing in.
        if ($user->owner()) {
            if ($changed !== []) {
                $confirmation->require($request, $user);
            }
        } elseif ($loginChanged) {
            $request->validate(['current_password' => ['required', 'current_password']]);
        }

        DB::transaction(function () use ($request, $user, $data, $phone, $phone2, $changed): void {
            $previousEmail = $user->email;
            $updates = ['name' => $data['name'], 'phone' => $phone];
            if (in_array('email', $changed, true)) {
                $updates['email'] = $data['email'];
                $updates['email_verified_at'] = null;
                $updates['remember_token'] = Str::random(60);
            }
            $user->forceFill($updates)->save();
            $keys = app(OfficeProfile::class)->saveProfileKeys($user, ['name_bn' => $data['name_bn'] ?? null, 'phone2' => $phone2]);
            if (in_array('email', $changed, true)) {
                DB::table('password_reset_tokens')->whereIn('email', [$previousEmail, $data['email']])->delete();
                DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
            }
            OfficeProfile::audit($user, 'office_profile.contact_updated', [...$changed, ...$keys]);
        });
        if (in_array('email', $changed, true)) {
            $request->session()->regenerate();
        }

        return back()->with('success', __('office_profile.flash_contact_saved'));
    }

    /** Card 2: date of birth, blood group, gender, addresses and the emergency contact. */
    public function personal(Request $request, OfficeProfile $office, AccountPhoneRules $phones): RedirectResponse
    {
        $user = $this->person($request);
        $data = $request->validate([
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'blood_group' => ['nullable', Rule::in(OfficeProfile::BloodGroups)],
            'gender' => ['nullable', Rule::in(OfficeProfile::Genders)],
            'present_address' => ['nullable', 'string', 'max:300'],
            'permanent_address' => ['nullable', 'string', 'max:300'],
            'same_as_present' => ['nullable', 'boolean'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:60'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
        ]);
        if ($request->boolean('same_as_present')) {
            $data['permanent_address'] = $data['present_address'] ?? null;
        }
        $emergencyPhone = filled($data['emergency_contact_phone'] ?? null) ? $phones->normalize($data['emergency_contact_phone'], 'emergency_contact_phone') : null;
        $values = [
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'blood_group' => $data['blood_group'] ?? null,
            'gender' => $data['gender'] ?? null,
            'present_address' => $data['present_address'] ?? null,
            'permanent_address' => $data['permanent_address'] ?? null,
            'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
            'emergency_contact_relation' => $data['emergency_contact_relation'] ?? null,
            'emergency_contact_phone' => $emergencyPhone,
        ];
        $changed = $office->saveProfileKeys($user, $values);
        OfficeProfile::audit($user, 'office_profile.personal_updated', $changed);

        return back()->with('success', $changed === [] ? __('office_profile.flash_nothing_changed') : __('office_profile.flash_personal_saved'));
    }

    /** Card 3: National ID number and photo. The owner edits freely; staff are locked once the office has verified it. */
    public function identity(Request $request, OfficeProfile $office): RedirectResponse
    {
        $user = $this->person($request);
        $data = $request->validate([
            'nid' => ['nullable', 'string', 'max:30'],
            'nid_photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);
        $changed = $office->saveIdentity($user, $data['nid'] ?? null, $request->file('nid_photo'));
        OfficeProfile::audit($user, 'office_profile.identity_updated', $changed);

        return back()->with('success', $changed === [] ? __('office_profile.flash_nothing_changed') : __('office_profile.flash_identity_saved'));
    }

    /** Card 4 (owner, MD and Chairman only): the title shown under the name. */
    public function title(Request $request, OfficeProfile $office): RedirectResponse
    {
        $user = $this->person($request);
        abort_unless($user->owner(), 403);
        $data = $request->validate(['display_title' => ['nullable', 'string', 'max:100']]);
        $changed = $office->saveProfileKeys($user, ['display_title' => $data['display_title'] ?? null]);
        OfficeProfile::audit($user, 'office_profile.title_updated', $changed);

        return back()->with('success', $changed === [] ? __('office_profile.flash_nothing_changed') : __('office_profile.flash_title_saved'));
    }

    private function person(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && OfficeProfile::applies($user), 403);

        return $user;
    }
}
