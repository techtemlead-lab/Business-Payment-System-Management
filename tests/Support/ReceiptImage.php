<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * A payout receipt picture that passes the blank-image check: a phone-sized PNG with dark text-like
 * lines on a light background. `UploadedFile::fake()->image()` makes one flat colour, which the
 * payout desk now refuses as blank (MA-25).
 */
final class ReceiptImage
{
    public static function make(string $name = 'receipt.png', int $width = 360, int $height = 640): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new RuntimeException('GD could not create the receipt image.');
        }
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 245, 245, 245));
        $ink = (int) imagecolorallocate($image, 30, 30, 30);
        for ($line = 40; $line < $height - 40; $line += 36) {
            imagefilledrectangle($image, 24, $line, $width - 24 - ($line % 90), $line + 10, $ink);
        }
        $path = tempnam(sys_get_temp_dir(), 'receipt');
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
