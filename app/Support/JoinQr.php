<?php

namespace App\Support;

use Mpdf\QrCode\Output\Png;
use Mpdf\QrCode\Output\Svg;
use Mpdf\QrCode\QrCode;

/**
 * Generates scannable join-link QR codes using the vendored mPDF QR library
 * (LGPL) — no Composer package added to the lockfile.
 */
final class JoinQr
{
    public static function svg(string $url, int $size = 160): string
    {
        $qr = new QrCode($url);
        $qr->disableBorder();

        return (new Svg)->output($qr, $size, '#ffffff', '#011627');
    }

    public static function png(string $url, int $size = 160): string
    {
        $qr = new QrCode($url);
        $qr->disableBorder();

        return (new Png)->output($qr, $size, [255, 255, 255], [1, 22, 39], 6);
    }
}
