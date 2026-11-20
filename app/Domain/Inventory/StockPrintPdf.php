<?php

namespace App\Domain\Inventory;

use App\Models\User;
use App\Support\Audit;
use App\Support\LocalDigits;
use App\Support\PdfFonts;
use App\Support\PdfLetterhead;
use App\Support\PdfPaper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The stock print: one PDF of everything the Stock page shows for the chosen branch and filters,
 * with the product details beside the figures, in the language the staff member reads the portal in.
 * Cost figures are printed only for staff who may see costs. It only reads; nothing here changes stock.
 */
final class StockPrintPdf
{
    public function __construct(private StockPrintRows $rows) {}

    public function download(User $actor, Request $request, BranchScope $scope): BinaryFileResponse
    {
        $stock = $this->rows->build($request, $scope, $actor);
        $now = now('Asia/Dhaka');

        $dir = Storage::disk('local')->path('report-exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = 'Madina-stock-print-'.$now->format('Y-m-d').'.pdf';
        $path = $dir.'/stock-print-'.$now->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.pdf';

        try {
            $html = view('invoices.stock-print', [
                'company' => PdfLetterhead::company(),
                'stock' => $stock,
                'printedAt' => LocalDigits::text($now->format('d/m/Y, H:i')),
            ])->render();
            $pdf = new Mpdf([
                'tempDir' => $dir,
                'format' => PdfPaper::Landscape,
                'margin_left' => 8,
                'margin_right' => 8,
                'margin_top' => 8,
                'margin_bottom' => 14,
                'margin_footer' => 5,
                ...PdfFonts::options(),
            ]);
            $pdf->SetTitle(__('stock_print.title'));
            $pdf->SetHTMLFooter(
                '<table width="100%" style="font-size:9pt;color:#475569"><tr><td>'.e(__('stock_print.footer', ['date' => LocalDigits::text($now->format('d/m/Y'))])).'</td>'
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
        }

        Audit::record($actor->id, 'report.exported', 'stock-print', [
            'rows' => count($stock['rows']),
            'branch' => $scope->allBranches ? 'all' : ($scope->branch->code ?? ''),
            'filtered' => $stock['filtered'],
            'with_cost' => $stock['shows_cost'],
        ]);

        return response()->download($path, $filename, ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }
}
