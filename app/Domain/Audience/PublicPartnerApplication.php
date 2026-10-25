<?php

namespace App\Domain\Audience;

use App\Domain\Notifications\PartnerEventSms;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PublicPartnerApplication
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data): string
    {
        $id = DB::transaction(function () use ($data): string {
            $email = mb_strtolower(trim((string) $data['email']));
            if (DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->exists()
                || DB::table('dealer_applications')->whereRaw('LOWER(email) = ?', [$email])->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['email' => __('portal.err_public_app_reject')]);
            }

            $id = (string) Str::uuid();
            DB::table('dealer_applications')->insert([
                'id' => $id,
                'submitted_by' => null,
                'email' => $email,
                'data' => json_encode([
                    'name' => $data['name'],
                    'email' => $email,
                    'phone' => $data['phone'],
                    'role' => $data['role'],
                    'division' => $data['division'],
                    'district' => $data['district'],
                    'upazila' => $data['upazila'] ?? null,
                    'message' => $data['message'] ?? null,
                    'sponsor_code' => $data['sponsor_code'] ?? null,
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

            return $id;
        }, 3);

        $locale = app()->getLocale();
        $roleLabel = match ((string) $data['role']) {
            'sub_dealer' => __('portal.acct_role_sub_dealer', [], $locale),
            default => __('portal.acct_role_dealer', [], $locale),
        };
        app(PartnerEventSms::class)->sendToPhone(
            (string) $data['phone'],
            'partnership.application_received',
            __('sms.partnership_application_received', ['role' => $roleLabel], $locale),
        );

        return $id;
    }
}
