<?php

namespace App\Domain\Inventory;

use App\Models\User;
use App\Support\Audit;
use App\Support\LocalDigits;
use App\Support\PdfFonts;
use App\Support\PdfLetterhead;
use App\Support\PdfPaper;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The list of live products (the ones the public website shows) as an invoice-style PDF for staff who may see the Products page,
 * in the language they are reading the portal in. It is the same list for every branch.
 */
final class ProductListPdf
{
    public function __construct(private ProductListRows $list) {}

    public function download(User $actor, bool $withPictures = true): BinaryFileResponse
    {
        $stock = $this->list->build();
        $now = now('Asia/Dhaka');

        $dir = Storage::disk('local')->path('report-exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = 'Madina-product-list-'.($withPictures ? '' : 'no-pictures-').$now->format('Y-m-d').'.pdf';
        $path = $dir.'/product-list-'.$now->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.pdf';
        $thumbnails = [];

        try {
            $html = view('invoices.product-list', [
                'company' => $this->company(),
                'rows' => $withPictures ? PdfPhotos::withThumbnails($stock['rows'], $dir, $thumbnails) : $stock['rows'],
                'withPictures' => $withPictures,
            ])->render();
            $pdf = new Mpdf([
                'tempDir' => $dir,
                'format' => PdfPaper::Landscape,
                'margin_left' => 10,
                'margin_right' => 10,
                'margin_top' => 10,
                'margin_bottom' => 16,
                'margin_footer' => 6,
                ...PdfFonts::options(),
            ]);
            $pdf->SetTitle(__('product_list_print.title'));
            $pdf->SetHTMLFooter(
                '<table width="100%" style="font-size:10pt;color:#475569"><tr><td>'.e(__('product_list_print.footer', ['date' => LocalDigits::text($now->format('d/m/Y'))])).'</td>'
                .'<td align="right">'.e(__('product_list_print.page')).' {PAGENO} / {nbpg}</td></tr></table>',
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

        Audit::record($actor->id, 'report.exported', 'product-list', [
            'rows' => count($stock['rows']),
        ]);

        return response()->download($path, $filename, ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }

    /**
     * The letterhead as the owner set it in the website editor.
     *
     * @return array{name: string, address: string, whatsapp: string, mobile: string, email: string, website: string, website_url: string, icons: array<string, string>, licence: string, trademark: string, logo_path: ?string}
     */
    public function company(): array
    {
        return PdfLetterhead::company();
    }
}
