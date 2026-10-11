<?php

namespace App\Services;

/** RFC 6238 HMAC-SHA1 TOTP. Secrets are encrypted using Laravel's application key. */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function secret(): string
    {
        $s = '';
        foreach (str_split(random_bytes(20)) as $char) {
            $s .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($part) => self::ALPHABET[bindec($part)], str_split($s, 5)));
    }

    public function code(string $secret, int $time, int $digits = 6): string
    {
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $c) {
            $p = strpos(self::ALPHABET, $c);
            if ($p === false) {
                throw new \InvalidArgumentException('Invalid TOTP secret');
            }$bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $key .= chr(bindec($byte));
            }
        }
        $counter = intdiv($time, 30);
        $hash = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), $key, true);
        $offset = ord($hash[19]) & 15;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->matchingStep($secret, $code) !== null;
    }

    public function matchingStep(string $secret, string $code, ?int $at = null): ?int
    {
        if (! preg_match('/^\d{6}$/D', $code)) {
            return null;
        }
        foreach ([-30, 0, 30] as $offset) {
            if (hash_equals($this->code($secret, ($at ?? time()) + $offset), $code)) {
                return intdiv(($at ?? time()) + $offset, 30);
            }
        }

        return null;
    }
}
