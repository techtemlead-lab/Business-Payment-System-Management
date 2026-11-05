<?php

namespace App\Http\Portals\Shared\Sections;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in account: its profile and its notices.
 */
final class ProfileSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        return match ($context->section) {
            'profile' => $this->profile($context->user),
            'notifications' => $this->notices($context->user),
            default => [],
        };
    }

    /**
     * The account's profile with its KYC status.
     *
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        $props = [];
        $profile = is_array($user->profile) ? $user->profile : [];
        $identityType = (string) ($profile['identity_type'] ?? ($user->nid ? 'nid' : ''));
        $hasBirthCert = filled($profile['birth_certificate_number'] ?? null);
        $identityOnFile = ($identityType === 'nid' && filled($user->nid))
            || ($identityType === 'birth_certificate' && $hasBirthCert)
            || filled($user->nid)
            || $hasBirthCert;
        $props['profile'] = [
            ...$user->safe(),
            'phone' => $user->phone,
            'shop_name' => $profile['shop_name'] ?? $profile['pharmacy'] ?? null,
            'place_code' => $profile['place_code'] ?? $profile['area_code'] ?? null,
            'name_bn' => $profile['name_bn'] ?? null,
            'upazila' => $profile['upazila'] ?? null,
            'union' => $profile['union'] ?? null,
            'village' => $profile['village'] ?? null,
            'refer_code' => $user->refer_code,
            'kyc' => [
                'identity_type' => $identityType !== '' ? $identityType : null,
                'identity_label' => match ($identityType) {
                    'nid' => __('portal.kyc_label_nid'),
                    'birth_certificate' => __('portal.kyc_label_birth_certificate'),
                    default => __('portal.kyc_label_identity'),
                },
                'identity_status' => $identityOnFile ? 'on_file' : 'missing',
                'identity_status_label' => $identityOnFile
                    ? __('portal.kyc_status_on_file')
                    : __('portal.kyc_status_missing'),
                'date_of_birth_on_file' => filled($profile['date_of_birth'] ?? null),
            ],
        ];

        return $props;
    }

    /**
     * The account's notices, newest first.
     *
     * @return array<string, mixed>
     */
    private function notices(User $user): array
    {
        return [
            'notices' => DB::table('account_notices')->where('user_id', $user->id)->latest()->paginate(20),
        ];
    }
}
