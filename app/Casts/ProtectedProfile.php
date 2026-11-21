<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * The account profile JSON, with the most personal details encrypted at rest (LC-05).
 *
 * Date of birth, parents' names, village, post office, birth-certificate number, the office profile addresses,
 * a partner's permanent (home) address and emergency contact are stored as
 * "enc:<ciphertext>" inside the JSON; every other key (place codes, shop name, permissions …) stays
 * plain so the place and permission queries keep working. Reading through the model gives the
 * plain values back, so screens, PDFs and checks are unchanged. Values written before this cast
 * (plain text) are still read and are encrypted the next time the profile is saved, or by the
 * migration that came with this change.
 *
 * @implements CastsAttributes<array<string, mixed>|null, mixed>
 */
final class ProtectedProfile implements CastsAttributes
{
    public const ProtectedKeys = ['date_of_birth', 'father_name', 'mother_name', 'village', 'post_office', 'birth_certificate_number', 'present_address', 'permanent_address', 'emergency_contact_name', 'emergency_contact_phone', 'permanent_division', 'permanent_district', 'permanent_upazila', 'permanent_union', 'permanent_place_id'];

    private const Marker = 'enc:';

    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        $profile = is_array($value) ? $value : json_decode((string) $value, true);

        return is_array($profile) ? self::reveal($profile) : null;
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        return json_encode(self::protect((array) $value), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public static function protect(array $profile): array
    {
        foreach (self::ProtectedKeys as $field) {
            $plain = $profile[$field] ?? null;
            if (is_string($plain) && $plain !== '' && ! str_starts_with($plain, self::Marker)) {
                $profile[$field] = self::Marker.Crypt::encryptString($plain);
            }
        }

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public static function reveal(array $profile): array
    {
        foreach (self::ProtectedKeys as $field) {
            $stored = $profile[$field] ?? null;
            if (is_string($stored) && str_starts_with($stored, self::Marker)) {
                try {
                    $profile[$field] = Crypt::decryptString(substr($stored, strlen(self::Marker)));
                } catch (DecryptException) {
                    // APP_KEY changed since it was written: show nothing rather than the ciphertext.
                    $profile[$field] = null;
                }
            }
        }

        return $profile;
    }
}
