<?php

namespace App\Domain\Staff;

use App\Domain\Accounts\PartnerAccountProvisioning;
use App\Domain\Accounts\PartnerAge;
use App\Models\AreaDealerAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the reviewer should know before approving: the applicant's age, ID number, whether the same person
 * or phone already has an account or another application, who already holds the upazila, and the sponsor.
 * Everything here only reads; the reviewer decides.
 */
final class ApplicationReviewChecks
{
    /**
     * @param  array<string, mixed>  $payload  The application's saved answers
     * @return array{age: int|null, nid: string|null, warnings: list<array{kind: string, text: string}>, area_holder: array{name: string, code: string}|null, sponsor: array{name: string, role: string, upazila: string|null}|null}
     */
    public function for(string $id, string $email, array $payload): array
    {
        $warnings = [];
        $age = $this->ageOf($payload['date_of_birth'] ?? null);
        if ($age === null) {
            $warnings[] = ['kind' => 'dob_missing', 'text' => __('joining.check_dob_missing')];
        } elseif ($age < PartnerAge::MinimumYears) {
            $warnings[] = ['kind' => 'under_age', 'text' => __('joining.check_under_age', ['years' => PartnerAge::MinimumYears, 'age' => $age])];
        }
        $nid = filled($payload['nid'] ?? null) ? (string) $payload['nid'] : null;
        if ($nid === null) {
            $warnings[] = ['kind' => 'nid_missing', 'text' => __('joining.check_nid_missing')];
        }

        $phone = (string) ($payload['phone'] ?? '');
        if ($phone !== '') {
            $holder = User::query()->where('phone', $phone)->first(['name', 'dealer_code']);
            if ($holder) {
                $warnings[] = ['kind' => 'phone_in_use', 'text' => __('joining.check_phone_in_use', ['name' => $holder->name, 'code' => (string) $holder->dealer_code])];
            }
            $other = DB::table('dealer_applications')->where('id', '!=', $id)->whereIn('status', ['pending', 'approved', 'correction'])
                ->whereRaw("data->>'phone' = ?", [$phone])->first(['application_number', 'status']);
            if ($other) {
                $warnings[] = ['kind' => 'phone_in_application', 'text' => __('joining.check_phone_in_application', ['number' => (string) $other->application_number])];
            }
        }
        if (User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->exists()) {
            $warnings[] = ['kind' => 'email_in_use', 'text' => __('joining.check_email_in_use')];
        }
        $name = trim((string) ($payload['name'] ?? ''));
        $born = (string) ($payload['date_of_birth'] ?? '');
        if ($name !== '' && $born !== '') {
            $same = User::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->whereRaw("profile->>'date_of_birth' = ?", [$born])->first(['name', 'dealer_code']);
            if ($same) {
                $warnings[] = ['kind' => 'same_person', 'text' => __('joining.check_same_person', ['name' => $same->name, 'code' => (string) $same->dealer_code])];
            }
        }
        if ($nid !== null) {
            $sameId = DB::table('dealer_applications')->where('id', '!=', $id)->whereIn('status', ['pending', 'approved', 'correction'])
                ->whereRaw("data->>'nid' = ?", [$nid])->first(['application_number']);
            if ($sameId) {
                $warnings[] = ['kind' => 'nid_in_application', 'text' => __('joining.check_nid_in_application', ['number' => (string) $sameId->application_number])];
            }
        }

        $areaHolder = null;
        if (is_numeric($payload['upazila_place_id'] ?? null)) {
            $seat = AreaDealerAssignment::query()->where('is_active', true)->where('place_code_id', (int) $payload['upazila_place_id'])->with('user:id,name,dealer_code')->first();
            if ($seat?->user) {
                $areaHolder = ['name' => (string) $seat->user->getAttribute('name'), 'code' => (string) $seat->user->getAttribute('dealer_code')];
            }
        }

        $sponsor = null;
        if (filled($payload['sponsor_code'] ?? null)) {
            $found = app(PartnerAccountProvisioning::class)->findSponsor((string) $payload['sponsor_code']);
            if ($found) {
                /** @var array<string, mixed> $profile */
                $profile = $found->getAttribute('profile') ?? [];
                $sponsor = ['name' => (string) $found->name, 'role' => (string) $found->role, 'upazila' => $profile['upazila'] ?? null];
            }
        }

        return ['age' => $age, 'nid' => $nid, 'warnings' => $warnings, 'area_holder' => $areaHolder, 'sponsor' => $sponsor];
    }

    private function ageOf(mixed $dateOfBirth): ?int
    {
        if (! is_string($dateOfBirth) || trim($dateOfBirth) === '') {
            return null;
        }
        try {
            $zone = (string) config('madina.business_timezone', 'Asia/Dhaka');

            return (int) floor(CarbonImmutable::parse($dateOfBirth, $zone)->diffInYears(CarbonImmutable::now($zone), true));
        } catch (\Throwable) {
            return null;
        }
    }
}
