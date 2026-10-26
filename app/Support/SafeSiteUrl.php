<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class SafeSiteUrl
{
    /** @param array<string|int, mixed> $values @return array<string|int, mixed> */
    public static function clean(array $values, bool $validate = false, string $prefix = 'customization'): array
    {
        foreach ($values as $key => $value) {
            $path = $prefix.'.'.$key;
            if (is_array($value)) {
                $values[$key] = self::clean($value, $validate, $path);
            } elseif (is_string($key) && (preg_match('/(^|_)(url|link|image|image_path)$/D', $key) || str_starts_with($key, 'social_'))) {
                if ($key === 'social_whatsapp' && is_string($value) && PhoneIdentity::normalize($value) !== null) {
                    continue;
                }
                $image = str_contains($key, 'image') || str_contains($key, 'logo');
                if (! is_string($value) || ! self::allowed($value, $image)) {
                    if ($validate) {
                        throw ValidationException::withMessages([$path => __('portal.err_safe_url')]);
                    }
                    $values[$key] = '';
                }
            }
        }

        return $values;
    }

    private static function allowed(string $value, bool $image): bool
    {
        $value = trim($value);
        if ($value === '') {
            return true;
        }
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $value)) {
            return false;
        }
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return true;
        }
        if (! $image && str_starts_with($value, '#')) {
            return true;
        }
        if (! $image && preg_match('/^tel:\+?[0-9 () .-]+$/Di', $value)) {
            return true;
        }
        if (! $image && str_starts_with(strtolower($value), 'mailto:')) {
            return filter_var(substr($value, 7), FILTER_VALIDATE_EMAIL) !== false;
        }
        if ($image && strlen($value) <= 8 * 1024 * 1024 && preg_match('#^data:image/(png|jpeg|gif|webp);base64,[A-Za-z0-9+/=]+$#D', $value)) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['https', 'http'], true)
            && parse_url($value, PHP_URL_USER) === null && parse_url($value, PHP_URL_PASS) === null;
    }
}
