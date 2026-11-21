<?php

namespace App\Domain\Inventory;

use App\Support\StockImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Product pictures for the printed lists: the same picture the website shows (the saved photo, or
 * the default picture of the category when there is none), as a small white-backed JPEG the PDF
 * library can embed. Used by the product list and by the stock print.
 */
final class PdfPhotos
{
    /**
     * The longest side of a photo copy in the PDF, in pixels: sharp when printed at about 34 mm
     * (CR-00, the owner asked for pictures you can recognise), still only tens of kilobytes.
     */
    public const ThumbnailPixels = 400;

    /** The box the photo is drawn in, in CSS pixels (about 34 mm); the photo keeps its shape inside it. */
    public const DisplayPixels = 130;

    /** JPEG quality of the copies: a little lower than usual keeps a 95-product list small enough to mail. */
    private const JpegQuality = 75;

    /** Photos with more pixels than this are left out rather than risk running out of memory. */
    private const MaxPhotoPixels = 40_000_000;

    /**
     * The picture the website shows for a product, as a file on this server: the saved photo, or,
     * when the owner has not uploaded one (or the file is gone), the default picture of its category.
     * It is never empty.
     */
    public static function sourceFor(?int $productId, mixed $imagePath, ?string $category): string
    {
        if (is_string($imagePath) && $imagePath !== '') {
            $path = Storage::disk('local')->path($imagePath);
            if (is_file($path)) {
                return $path;
            }
            Log::warning('Printed list: the saved product photo is missing, the default picture is used.', ['product_id' => $productId, 'image_path' => $imagePath]);
        }

        return StockImage::pdfFileForCategory($category);
    }

    /**
     * Swaps each row's picture for a copy 400 px on its longest side, drawn about 34 mm wide so the
     * product can be recognised; the full file would make a list of 95 products tens of megabytes,
     * too big to send by mail. The copies are listed in $created so the caller can delete them once
     * the PDF is written. A picture that cannot be read is logged and replaced by the default one.
     *
     * @param  list<array<string, mixed>>  $rows  each with image, category and product_id
     * @param  list<string>  $created
     * @param  int  $displayPixels  the box the picture is drawn in, in CSS pixels
     * @return list<array<string, mixed>>
     */
    public static function withThumbnails(array $rows, string $dir, array &$created, int $displayPixels = self::DisplayPixels): array
    {
        foreach ($rows as $index => $row) {
            $source = $row['image'] ?? null;
            if (! is_string($source) || $source === '') {
                continue;
            }
            $target = $dir.'/stock-photo-'.Str::lower(Str::random(10)).'.jpg';
            $size = self::thumbnail($source, $target);
            if ($size === null) {
                Log::warning('Printed list: a product photo could not be read, the default picture is used.', ['product_id' => $row['product_id'] ?? null, 'file' => $source]);
                $size = self::thumbnail(StockImage::pdfFileForCategory($row['category'] ?? null), $target);
            }
            if ($size !== null) {
                $created[] = $target;
                $rows[$index]['image'] = $target;
                // Drawn inside the display box with its own shape, never squeezed into a square.
                $scale = $displayPixels / max($size[0], $size[1]);
                $rows[$index]['image_width'] = max(1, (int) round($size[0] * $scale));
                $rows[$index]['image_height'] = max(1, (int) round($size[1] * $scale));
            } else {
                $rows[$index]['image'] = null;
            }
        }

        return $rows;
    }

    /**
     * A white-backed JPEG copy of a photo, at most ThumbnailPixels on its longest side.
     *
     * @return array{0: int, 1: int}|null the copy's width and height; null when the file is not a readable picture
     */
    private static function thumbnail(string $source, string $target): ?array
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

        $scale = min(1.0, self::ThumbnailPixels / max($size[0], $size[1]));
        $width = max(1, (int) round($size[0] * $scale));
        $height = max(1, (int) round($size[1] * $scale));
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $photo, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);

        return imagejpeg($canvas, $target, self::JpegQuality) ? [$width, $height] : null;
    }
}
