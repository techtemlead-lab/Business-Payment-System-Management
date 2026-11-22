<?php

namespace Tests\Unit;

use App\Domain\Payouts\PayoutReceiptCheck;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * A WebP screenshot goes through the same blank-picture check as a JPEG or PNG once the image
 * library can read WebP (the production image installs libwebp for it).
 */
class PayoutReceiptWebpTest extends TestCase
{
    public function test_a_blank_webp_receipt_is_flagged_and_a_busy_one_is_not(): void
    {
        if (! function_exists('imagewebp') || ! (gd_info()['WebP Support'] ?? false)) {
            $this->markTestSkipped('This PHP image library (GD) was built without WebP support.');
        }

        $blank = imagecreatetruecolor(400, 400);
        imagefill($blank, 0, 0, (int) imagecolorallocate($blank, 250, 250, 250));
        $busy = imagecreatetruecolor(400, 400);
        imagefill($busy, 0, 0, (int) imagecolorallocate($busy, 250, 250, 250));
        imagefilledrectangle($busy, 0, 0, 400, 200, (int) imagecolorallocate($busy, 10, 10, 10));

        $this->assertSame('blank', PayoutReceiptCheck::imageProblem($this->upload($blank)));
        $this->assertNull(PayoutReceiptCheck::imageProblem($this->upload($busy)));
    }

    private function upload(\GdImage $image): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'receipt').'.webp';
        imagewebp($image, $path, 90);

        return new UploadedFile($path, 'receipt.webp', 'image/webp', null, true);
    }
}
