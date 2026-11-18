<?php

namespace App\Casts;

use App\Support\PasswordVault;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Like Laravel's "encrypted" cast, but with the separate password-vault key (see PasswordVault).
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class PasswordVaultEncrypted implements CastsAttributes
{
    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return app(PasswordVault::class)->decrypt($value);
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return app(PasswordVault::class)->encrypt((string) $value);
    }
}
