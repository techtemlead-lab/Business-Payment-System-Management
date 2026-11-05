<?php

namespace App\Domain\Auth;

use Illuminate\Http\Request;

/**
 * In-house picture captcha for the sign-in form.
 *
 * The text lives only in the server-side session. Each picture replaces the
 * previous one, expires after a few minutes, and is thrown away after the first
 * answer — right or wrong — so a solved picture can never be replayed.
 */
final class LoginCaptcha
{
    public const SessionKey = 'login_captcha';

    /** No 0/O, 1/I/L, or 5/S look-alikes, so phone users are not tripped up. */
    public const Alphabet = 'ABCDEFGHJKMNPQRTUVWXYZ2346789';

    public const Length = 5;

    public const TtlSeconds = 180;

    private const Width = 220;

    private const Height = 72;

    public function issue(Request $request): string
    {
        $text = '';
        $last = strlen(self::Alphabet) - 1;
        for ($i = 0; $i < self::Length; $i++) {
            $text .= self::Alphabet[random_int(0, $last)];
        }
        $request->session()->put(self::SessionKey, [
            'text' => $text,
            'expires_at' => now()->addSeconds(self::TtlSeconds)->getTimestamp(),
        ]);

        return $text;
    }

    /**
     * Check the typed answer and always discard the stored picture (single use).
     *
     * @return 'ok'|'missing'|'expired'|'wrong'
     */
    public function check(Request $request, ?string $answer): string
    {
        $stored = $request->session()->pull(self::SessionKey);
        if (! is_array($stored) || ! is_string($stored['text'] ?? null)) {
            return 'missing';
        }
        if ((int) ($stored['expires_at'] ?? 0) < now()->getTimestamp()) {
            return 'expired';
        }
        $typed = strtoupper((string) preg_replace('/\s+/', '', (string) $answer));

        return $typed !== '' && hash_equals($stored['text'], $typed) ? 'ok' : 'wrong';
    }

    /** Draw the text as a PNG: large, high-contrast letters with light noise so it stays readable on phones. */
    public function png(string $text): string
    {
        $image = imagecreatetruecolor(self::Width, self::Height);
        imagefilledrectangle($image, 0, 0, self::Width, self::Height, imagecolorallocate($image, 246, 248, 250));

        for ($i = 0; $i < 140; $i++) {
            $dot = imagecolorallocate($image, random_int(150, 210), random_int(150, 210), random_int(150, 210));
            imagesetpixel($image, random_int(0, self::Width - 1), random_int(0, self::Height - 1), $dot);
        }
        for ($i = 0; $i < 4; $i++) {
            $line = imagecolorallocate($image, random_int(160, 200), random_int(160, 200), random_int(160, 200));
            imagesetthickness($image, 2);
            imageline($image, random_int(0, 40), random_int(0, self::Height), random_int(self::Width - 40, self::Width), random_int(0, self::Height), $line);
        }

        $font = 5;
        $glyphWidth = imagefontwidth($font);
        $glyphHeight = imagefontheight($font);
        $cell = intdiv(self::Width - 20, max(1, strlen($text)));
        foreach (str_split($text) as $index => $character) {
            $glyph = imagecreatetruecolor($glyphWidth + 4, $glyphHeight + 4);
            $background = imagecolorallocate($glyph, 246, 248, 250);
            imagefilledrectangle($glyph, 0, 0, $glyphWidth + 4, $glyphHeight + 4, $background);
            imagestring($glyph, $font, 2, 2, $character, imagecolorallocate($glyph, random_int(10, 70), random_int(20, 80), random_int(60, 120)));
            $rotated = imagerotate($glyph, random_int(-14, 14), $background);
            imagecolortransparent($rotated, $background);
            $targetWidth = (int) round($cell * 0.78);
            $targetHeight = self::Height - 18;
            imagecopyresized(
                $image,
                $rotated,
                10 + $index * $cell + random_int(0, 4),
                random_int(4, 14),
                0,
                0,
                $targetWidth,
                $targetHeight,
                imagesx($rotated),
                imagesy($rotated),
            );
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
