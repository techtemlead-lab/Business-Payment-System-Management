<?php

namespace App\Domain\Audience;

use App\Domain\Accounts\AccountPhoneRules;
use App\Domain\Accounts\IdentityKeys;
use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Notifications\SmsText;
use App\Support\Audit;
use App\Support\PhoneIdentity;
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
            if (IdentityKeys::emailTaken($email)) {
                throw ValidationException::withMessages(['email' => __('portal.err_public_app_reject')]);
            }
            $open = DB::table('dealer_applications')->whereRaw('LOWER(email) = ?', [$email])->whereIn('status', ['pending', 'correction'])->first(['application_number']);
            if ($open) {
                throw ValidationException::withMessages(['email' => __('joining.err_already_applied', ['number' => $open->application_number, 'url' => '/partnership/status'])]);
            }
            // One number, one account (owner's switch): an open application already holding this mobile is refused too,
            // so the office never finds two applications it can approve only one of.
            $identity = PhoneIdentity::normalize((string) $data['phone']);
            if ($identity !== null && app(AccountPhoneRules::class)->enforced()) {
                $openForPhone = DB::table('dealer_applications')
                    ->whereIn('status', ['pending', 'correction'])
                    ->whereRaw('('.PhoneIdentity::sqlFor("COALESCE(data->>'phone', '')").') = ?', [$identity])
                    ->exists();
                if ($openForPhone) {
                    // The same words as a number held by an account: never which application holds it.
                    throw ValidationException::withMessages(['phone' => __('portal.err_public_app_phone_taken')]);
                }
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
                    'shop_name' => filled($data['shop_name'] ?? null) ? trim((string) $data['shop_name']) : null,
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
