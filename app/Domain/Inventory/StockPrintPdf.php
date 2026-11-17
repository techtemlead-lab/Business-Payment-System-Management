<?php

namespace App\Domain\Inventory;

use App\Models\User;
use App\Support\Audit;
use App\Support\LetterheadContacts;
use App\Support\LocalDigits;
use App\Support\PdfFonts;
use App\Support\SiteCustomization;
use App\Support\SiteFacts;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The current stock list as an invoice-style PDF for staff who may see the Stock page,
 * in the language they are reading the portal in.
 */
final class StockPrintPdf
{
    /** The longest side of a photo copy in the PDF, in pixels: sharp at 12 mm, a few kilobytes. */
    private const ThumbnailPixels = 160;

    /** Photos with more pixels than this are left out rather than risk running out of memory. */
    private const MaxPhotoPixels = 40_000_000;

    public function __construct(private StockPrintList $list) {}

    public function download(User $actor, BranchScope $scope): BinaryFileResponse
    {
        $stock = $this->list->build($scope);
        $now = now('Asia/Dhaka');

        $dir = Storage::disk('local')->path('report-exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = 'Madina-stock-list-'.$now->format('Y-m-d').'.pdf';
        $path = $dir.'/stock-list-'.$now->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.pdf';
        $thumbnails = [];

        try {
            $html = view('invoices.stock-list', [
                'company' => $this->company(),
                'branch' => $scope->allBranches ? __('stock_print.all_branches') : (string) ($scope->branch->name ?? ''),
                'printed_at' => $now,
                'rows' => $this->withThumbnails($stock['rows'], $dir, $thumbnails),
            ])->render();
            $pdf = new Mpdf([
                'tempDir' => $dir,
                'format' => 'A4',
                'margin_left' => 10,
                'margin_right' => 10,
                'margin_top' => 10,
                'margin_bottom' => 16,
                'margin_footer' => 6,
                ...PdfFonts::options(),
            ]);
            $pdf->SetTitle(__('stock_print.title'));
            $pdf->SetHTMLFooter(
                '<table width="100%" style="font-size:10pt;color:#475569"><tr><td>'.e(__('stock_print.footer', ['date' => LocalDigits::text($now->format('d/m/Y'))])).'</td>'
                .'<td align="right">'.e(__('stock_print.page')).' {PAGENO} / {nbpg}</td></tr></table>',
            );
            if (app()->getLocale() === 'bn') {
                $pdf->AddPageByArray(['pagenumstyle' => 'bengali']);
            }
            $pdf->WriteHTML($html);
            $pdf->Output($path, Destination::FILE);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        } finally {
            foreach ($thumbnails as $file) {
                @unlink($file);
            }
        }

        Audit::record($actor->id, 'report.exported', 'stock-print', [
            'branch' => $scope->allBranches ? 'all' : ($scope->branch->code ?? ''),
            'rows' => count($stock['rows']),
        ]);

        return response()->download($path, $filename, ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }

    /**
     * Swaps each product photo for a small copy. The photo is shown about 12 mm wide, and the
     * full file would make a list of 95 products tens of megabytes, too big to send by mail.
     * The copies are listed in $created so the caller can delete them once the PDF is written.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $created
     * @return list<array<string, mixed>>
     */
    private function withThumbnails(array $rows, string $dir, array &$created): array
    {
        foreach ($rows as $index => $row) {
            $source = $row['image'] ?? null;
            if (! is_string($source) || $source === '') {
                continue;
            }
            $target = $dir.'/stock-photo-'.Str::lower(Str::random(10)).'.jpg';
            if ($this->thumbnail($source, $target)) {
                $created[] = $target;
                $rows[$index]['image'] = $target;
            } else {
                $rows[$index]['image'] = null;
            }
        }

        return $rows;
    }

    /** A small white-backed JPEG of a photo; false when the file is not a readable picture. */
    private function thumbnail(string $source, string $target): bool
    {
        $size = @getimagesize($source);
        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MaxPhotoPixels) {
            return false;
        }
        $bytes = @file_get_contents($source);
        $photo = $bytes === false ? false : @imagecreatefromstring($bytes);
        if ($photo === false) {
            return false;
        }

        $scale = min(1.0, self::ThumbnailPixels / max($size[0], $size[1]));
        $width = max(1, (int) round($size[0] * $scale));
        $height = max(1, (int) round($size[1] * $scale));
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $photo, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);

        return imagejpeg($canvas, $target, 82);
    }

    /**
     * The letterhead as the owner set it in the website editor.
     *
     * @return array{name: string, address: string, whatsapp: string, mobile: string, email: string, website: string, website_url: string, icons: array<string, string>, licence: string, trademark: string, logo_path: ?string}
     */
    public function company(): array
    {
        $custom = SiteCustomization::all();
        $defaults = SiteCustomization::defaults();
        $letterhead = static fn (string $key): string => filled($custom[$key] ?? null) ? (string) $custom[$key] : (string) ($defaults[$key] ?? '');
        $logoPath = SiteCustomization::logoFilePath();
        if ($logoPath === null || ! is_file($logoPath)) {
            $logoPath = public_path('madina-header-logo.png');
        }
        if (! is_file($logoPath)) {
            $logoPath = public_path('branding/madina-logo-latest.png');
        }
        $contacts = LetterheadContacts::lines();

        return [
            'name' => $letterhead('contact_company_title'),
            'address' => $letterhead('office_address'),
            ...$contacts,
            'icons' => LetterheadContacts::icons(),
            'licence' => SiteFacts::tradeLicence(),
            'trademark' => SiteFacts::trademarkNumber(),
            'logo_path' => is_file($logoPath) ? $logoPath : null,
        ];
    }
}
