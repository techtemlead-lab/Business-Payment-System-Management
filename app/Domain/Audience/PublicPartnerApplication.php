<?php

namespace App\Domain\Audience;

use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Notifications\SmsText;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PublicPartnerApplication
{
    /** The number the applicant keeps: APP- and the first eight characters of the application's id. */
    public static function numberFor(string $id): string
    {
        return 'APP-'.strtoupper(substr(str_replace('-', '', $id), 0, 8));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data): string
    {
        $id = DB::transaction(function () use ($data): string {
            $email = mb_strtolower(trim((string) $data['email']));
            if (DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages(['email' => __('portal.err_public_app_reject')]);
            }
            $open = DB::table('dealer_applications')->whereRaw('LOWER(email) = ?', [$email])->whereIn('status', ['pending', 'correction'])->first(['application_number']);
            if ($open) {
                throw ValidationException::withMessages(['email' => __('joining.err_already_applied', ['number' => $open->application_number, 'url' => '/partnership/status'])]);
            }

            $id = (string) Str::uuid();
            $number = self::numberFor($id);
            DB::table('dealer_applications')->insert([
                'id' => $id,
                'submitted_by' => null,
                'application_number' => $number,
                'email' => $email,
                'data' => json_encode([
                    'name' => $data['name'],
                    'email' => $email,
                    'phone' => $data['phone'],
                    'date_of_birth' => $data['date_of_birth'] ?? null,
                    'role' => $data['role'],
                    'division' => $data['division'],
                    'district' => $data['district'],
                    'upazila' => $data['upazila'] ?? null,
                    'union' => $data['union'] ?? null,
                    'nid' => $data['nid'] ?? null,
                    'division_place_id' => $data['division_place_id'] ?? null,
                    'district_place_id' => $data['district_place_id'] ?? null,
                    'upazila_place_id' => $data['upazila_place_id'] ?? null,
                    'union_place_id' => $data['union_place_id'] ?? null,
                    'message' => $data['message'] ?? null,
                    'sponsor_code' => $data['sponsor_code'] ?? null,
                    'locale' => app()->getLocale(),
                ], JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'source' => 'public',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Audit::record(null, 'dealer_application.public_submitted', $id, [
                'email' => $email,
                'role' => $data['role'],
                'division' => $data['division'],
                'district' => $data['district'],
            ]);
            $roleKey = $data['role'] === 'sub_dealer' ? 'portal.acct_role_sub_dealer' : 'portal.acct_role_dealer';
            Audit::notifyAdminsAndStaff(
                ['accounts'],
                fn (string $locale): string => __('portal.notice_admin_partner_apply_title', [], $locale),
                fn (string $locale): string => __('portal.notice_admin_partner_apply_body', [
                    'name' => (string) $data['name'],
                    'role' => __($roleKey, [], $locale),
                    'phone' => (string) $data['phone'],
                    'district' => (string) $data['district'],
                ], $locale)
            );

            return $id;
        }, 3);
        $number = self::numberFor($id);

        $roleLabel = SmsText::make((string) $data['role'] === 'sub_dealer' ? 'portal.acct_role_sub_dealer' : 'portal.acct_role_dealer');
        app(PartnerEventSms::class)->sendToPhone(
            (string) $data['phone'],
            'partnership.application_received',
            SmsText::make('sms.partnership_application_received', ['role' => $roleLabel, 'number' => $number]),
        );

        return $id;
    }
}
