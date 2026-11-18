<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Encrypts the readable copy of dealer and sub-dealer passwords with its own key, not APP_KEY.
 *
 * APP_KEY sits in .env.production and also signs sessions and cookies, so a database dump plus
 * that one file would give every password. The vault key is set with PASSWORD_VAULT_KEY, or kept
 * in a separate file named by PASSWORD_VAULT_KEY_FILE, which is left out of the backup set.
 * Until a vault key is set the copy falls back to APP_KEY and the go-live check warns about it.
 * Copies written under APP_KEY before the vault key existed still open; `php artisan
 * madina:password-vault-rekey` moves them to the vault key.
 */
final class PasswordVault
{
    public const Cipher = 'AES-256-CBC';

    private ?Encrypter $vault = null;

    private bool $resolved = false;

    public function configured(): bool
    {
        return $this->vault() !== null;
    }

    public function encrypt(string $plain): string
    {
        return ($this->vault() ?? Crypt::getFacadeRoot())->encryptString($plain);
    }

    /**
     * @throws DecryptException when neither the vault key nor APP_KEY opens the value
     */
    public function decrypt(string $payload): string
    {
        $vault = $this->vault();
        if ($vault !== null) {
            try {
                return $vault->decryptString($payload);
            } catch (DecryptException) {
                // Written before the vault key was set: still under APP_KEY.
            }
        }

        return Crypt::decryptString($payload);
    }

    /** True when the stored value already uses the vault key (nothing to re-key). */
    public function usesVaultKey(string $payload): bool
    {
        $vault = $this->vault();
        if ($vault === null) {
            return false;
        }
        try {
            $vault->decryptString($payload);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    /** A fresh key in the same "base64:" form as APP_KEY, for the set-up notes. */
    public static function generateKey(): string
    {
        return 'base64:'.base64_encode(Encrypter::generateKey(self::Cipher));
    }

    private function vault(): ?Encrypter
    {
        if ($this->resolved) {
            return $this->vault;
        }
        $this->resolved = true;
        $key = $this->configuredKey();
        if ($key === null) {
            return null;
        }
        if (Str::startsWith($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }
        if (! Encrypter::supported($key, self::Cipher)) {
            throw new \RuntimeException('PASSWORD_VAULT_KEY must be a 32-byte key (use "base64:" plus 44 characters, like APP_KEY).');
        }

        return $this->vault = new Encrypter($key, self::Cipher);
    }

    private function configuredKey(): ?string
    {
        $key = trim((string) config('madina.password_vault.key', ''));
        if ($key !== '') {
            return $key;
        }
        $file = trim((string) config('madina.password_vault.key_file', ''));
        if ($file !== '' && is_readable($file)) {
            $fromFile = trim((string) file_get_contents($file));

            return $fromFile !== '' ? $fromFile : null;
        }

        return null;
    }
}
