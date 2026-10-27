<?php

namespace App\Domain\Auth;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * RFC 6238 TOTP (SHA-1, 30s step, 6 digits) without external packages.
 */
final class TotpAuthenticator
{
    private const Period = 30;

    private const Digits = 6;

    private const Window = 1;

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    public function provisioningUri(User $user, string $secret, ?string $issuer = null): string
    {
        $issuer = $issuer ?: (string) config('madina.company_name', config('app.name'));
        $label = rawurlencode($issuer.':'.$user->email);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::Digits,
            'period' => self::Period,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\s+/', '', trim($code)) ?? '';
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $timestamp ??= time();
        $binarySecret = $this->base32Decode($secret);
        if ($binarySecret === '') {
            return false;
        }
        for ($offset = -self::Window; $offset <= self::Window; $offset++) {
            $slot = intdiv($timestamp, self::Period) + $offset;
            if (hash_equals($this->hotp($binarySecret, $slot), $code)) {
                return true;
            }
        }

        return false;
    }

    public function assertValid(string $secret, string $code): void
    {
        if (! $this->verify($secret, $code)) {
            throw ValidationException::withMessages(['code' => __('portal.err_totp_invalid')]);
        }
    }

    /** @return list<string> */
    public function recoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = Str::upper(Str::random(4).'-'.Str::random(4));
        }

        return $codes;
    }

    private function hotp(string $secret, int $counter): string
    {
        $binCounter = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $binCounter, $secret, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad((string) ($value % (10 ** self::Digits)), self::Digits, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $output .= $alphabet[bindec($chunk)];
        }

        return $output;
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        if ($secret === '') {
            return '';
        }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $char) {
            $index = strpos($alphabet, $char);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $output .= chr(bindec($chunk));
            }
        }

        return $output;
    }
}
