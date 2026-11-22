<?php

namespace App\Domain\Inventory;

use App\Support\StockImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

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
    public static function sourceFor(?int $productId, mixed $imagePath, ?string $category, mixed $originalPath = null): string
    {
        if (is_string($imagePath) && $imagePath !== '') {
            $path = Storage::disk('local')->path($imagePath);
            if (is_file($path)) {
                return $path;
            }
            $original = self::existingFile($originalPath);
            if ($original !== null) {
                Log::notice('Printed list: the processed product photo is missing, the original upload is used.', ['product_id' => $productId, 'image_path' => $imagePath]);

                return $original;
            }
            Log::warning('Printed list: the saved product photo is missing, the default picture is used.', ['product_id' => $productId, 'image_path' => $imagePath]);
        }

        return StockImage::pdfFileForCategory($category);
    }

    /**
     * The original upload of a product photo as a file on this server, to try when the processed
     * photo cannot be read; an empty list when there is none (or it is the picture already used).
     *
     * @return list<string>
     */
    public static function fallbacksFor(mixed $originalPath, string $usedFile): array
    {
        $original = self::existingFile($originalPath);

        return $original === null || $original === $usedFile ? [] : [$original];
    }

    private static function existingFile(mixed $storedPath): ?string
    {
        if (! is_string($storedPath) || $storedPath === '') {
            return null;
        }
        $path = Storage::disk('local')->path($storedPath);

        return is_file($path) ? $path : null;
    }

    /**
     * Swaps each row's picture for a copy 400 px on its longest side, drawn about 34 mm wide so the
     * product can be recognised; the full file would make a list of 95 products tens of megabytes,
     * too big to send by mail. The copies are listed in $created so the caller can delete them once
     * the PDF is written. A picture that cannot be read is logged and the original upload is tried
     * (row key image_fallbacks), and only then the default picture of the category. One log line
     * lists the products that ended up with the default picture, so the office can fix them.
     *
     * @param  list<array<string, mixed>>  $rows  each with image, category and product_id, and optionally image_fallbacks
     * @param  list<string>  $created
     * @param  int  $displayPixels  the box the picture is drawn in, in CSS pixels
     * @return list<array<string, mixed>>
     */
    public static function withThumbnails(array $rows, string $dir, array &$created, int $displayPixels = self::DisplayPixels): array
    {
        $onDefault = [];
        foreach ($rows as $index => $row) {
            $source = $row['image'] ?? null;
            if (! is_string($source) || $source === '') {
                continue;
            }
            $default = StockImage::pdfFileForCategory($row['category'] ?? null);
            $target = $dir.'/stock-photo-'.Str::lower(Str::random(10)).'.jpg';
            $size = null;
            $candidates = $source === $default ? [] : [$source, ...array_filter((array) ($row['image_fallbacks'] ?? []), 'is_string')];
            foreach ($candidates as $position => $candidate) {
                $size = self::thumbnail($candidate, $target);
                if ($size !== null) {
                    if ($position > 0) {
                        Log::notice('Printed list: the processed product photo could not be read, the original upload is used.', ['product_id' => $row['product_id'] ?? null, 'file' => $candidate]);
                    }
                    break;
                }
            }
            if ($size === null) {
                if ($candidates !== []) {
                    Log::warning('Printed list: a product photo could not be read, the default picture is used.', ['product_id' => $row['product_id'] ?? null, 'file' => $source]);
                }
                $size = self::thumbnail($default, $target);
                $onDefault[] = $row['product_id'] ?? null;
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
        if ($onDefault !== []) {
            Log::notice('Printed list: '.count($onDefault).' products show the default picture; upload a readable photo for them.', ['product_ids' => array_values(array_filter($onDefault, static fn (mixed $id): bool => $id !== null))]);
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
        if ($photo === false && $bytes !== false && $size[2] === IMAGETYPE_WEBP) {
            $photo = self::decodeWebpWithoutGd($source, $bytes);
        }
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

    /**
     * A WebP photo the GD library cannot open (a server built without WebP support, or an animated
     * file): the imagick extension when it is installed, else the dwebp program when it is on the
     * server. Nothing is installed for this; without either the caller falls back to another picture.
     *
     * @return \GdImage|false
     */
    private static function decodeWebpWithoutGd(string $source, string $bytes): mixed
    {
        if (extension_loaded('imagick')) {
            try {
                $image = new \Imagick;
                $image->readImageBlob($bytes);
                $image->setImageFormat('png');

                return @imagecreatefromstring($image->getImageBlob());
            } catch (\Throwable $exception) {
                Log::info('Printed list: imagick could not read a WebP photo.', ['file' => $source, 'error' => $exception->getMessage()]);
            }
        }
        $program = (new ExecutableFinder)->find('dwebp');
        if ($program === null) {
            return false;
        }
        $png = tempnam(sys_get_temp_dir(), 'webp');
        if ($png === false) {
            return false;
        }
        try {
            $run = new Process([$program, $source, '-o', $png]);
            $run->setTimeout(20);
            $run->run();
            $decoded = $run->isSuccessful() ? @file_get_contents($png) : false;

            return $decoded === false || $decoded === '' ? false : @imagecreatefromstring($decoded);
        } catch (\Throwable $exception) {
            Log::info('Printed list: dwebp could not read a WebP photo.', ['file' => $source, 'error' => $exception->getMessage()]);

            return false;
        } finally {
            @unlink($png);
        }
    }
}
