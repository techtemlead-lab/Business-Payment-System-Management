<?php

namespace App\Domain\Catalog;

use Illuminate\Support\Facades\Storage;

/**
 * A card-sized copy of a product photo for the public lists.
 *
 * A phone shows a product card about 350 px wide, yet the original photo can be a megabyte or
 * more, which made the home page slow. The copy is made once per photo version and kept on disk;
 * a new photo has a new version number in its address, so an old copy is never shown again.
 */
class ProductPhotoThumbnail
{
    /** The widths a list may ask for, so a visitor cannot make the server build endless copies. */
    public const Widths = [480];

    /** A photo with more pixels than this is not opened: the memory needed could stop the server. */
    private const MaxPhotoPixels = 40_000_000;

    /** The photo address with the card width added; an address that is not a product photo is returned as it is. */
    public static function address(string $imageUrl, int $width = 480): string
    {
        if ($imageUrl === '' || ! str_starts_with($imageUrl, '/products/') || ! in_array($width, self::Widths, true)) {
            return $imageUrl;
        }

        return $imageUrl.(str_contains($imageUrl, '?') ? '&' : '?').'w='.$width;
    }

    /**
     * The disk path of the copy, made now when it does not exist yet; null when the original
     * cannot be read as a picture, so the caller can send the original instead.
     */
    public function path(int $productId, string $originalPath, string $version, int $width): ?string
    {
        if (! in_array($width, self::Widths, true) || ! Storage::disk('local')->exists($originalPath)) {
            return null;
        }

        $safeVersion = preg_replace('/[^A-Za-z0-9]/', '', $version) ?: '0';
        $directory = 'product-thumbnails/'.$productId;
        $target = $directory.'/'.$safeVersion.'-'.$width.'.png';
        $jpegTarget = $directory.'/'.$safeVersion.'-'.$width.'.jpg';
        foreach ([$target, $jpegTarget] as $existing) {
            if (Storage::disk('local')->exists($existing)) {
                return $existing;
            }
        }

        $made = $this->make(Storage::disk('local')->path($originalPath), $width);
        if ($made === null) {
            return null;
        }

        // Copies of earlier versions of this photo are no longer reachable.
        foreach (Storage::disk('local')->files($directory) as $old) {
            Storage::disk('local')->delete($old);
        }
        $name = $made['png'] ? $target : $jpegTarget;
        Storage::disk('local')->put($name, $made['bytes']);

        return $name;
    }

    /**
     * @return array{png: bool, bytes: string}|null
     */
    private function make(string $source, int $width): ?array
    {
        $size = @getimagesize($source);
        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MaxPhotoPixels) {
            return null;
        }
        $bytes = @file_get_contents($source);
        $photo = $bytes === false ? false : @imagecreatefromstring($bytes);
        if ($photo === false) {
            return null;
        }

        $scale = min(1.0, $width / max($size[0], $size[1]));
        $targetWidth = max(1, (int) round($size[0] * $scale));
        $targetHeight = max(1, (int) round($size[1] * $scale));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        // A cut-out photo has a see-through background: keep it as PNG; any other photo is a smaller JPEG.
        $keepsTransparency = $size[2] === IMAGETYPE_PNG;
        if ($keepsTransparency) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        } else {
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        }
        imagecopyresampled($canvas, $photo, 0, 0, 0, 0, $targetWidth, $targetHeight, $size[0], $size[1]);

        ob_start();
        $keepsTransparency ? imagepng($canvas, null, 6) : imagejpeg($canvas, null, 82);
        $output = (string) ob_get_clean();

        return $output === '' ? null : ['png' => $keepsTransparency, 'bytes' => $output];
    }
}
