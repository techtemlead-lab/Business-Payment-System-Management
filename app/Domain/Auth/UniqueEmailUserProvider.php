<?php

namespace App\Domain\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * Password reset finds an account by its email address. Several accounts may share an email while the
 * owner's one-person switch is off, and signing in already refuses to pick one of them at random.
 * This provider does the same for the reset link: an email that matches more than one account
 * matches none, so the link is not sent and an old link changes nobody's password.
 */
class UniqueEmailUserProvider extends EloquentUserProvider
{
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        $lookup = array_filter(
            $credentials,
            fn ($key) => ! str_contains((string) $key, 'password'),
            ARRAY_FILTER_USE_KEY
        );

        if (count($lookup) !== 1 || ! is_string($lookup['email'] ?? null)) {
            return parent::retrieveByCredentials($credentials);
        }

        $email = Str::lower(trim($lookup['email']));
        if ($email === '') {
            return null;
        }

        $ids = $this->newModelQuery()->whereRaw('LOWER(email) = ?', [$email])->limit(2)->pluck($this->createModel()->getAuthIdentifierName());
        if ($ids->count() !== 1) {
            return null;
        }

        return $this->retrieveById($ids->first());
    }
}
