<?php

namespace App\Domain\Inventory;

use App\Models\User;
use App\Support\Audit;
use App\Support\LocalDigits;
use App\Support\PdfFonts;
use App\Support\SiteCustomization;
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
    public function __construct(private StockPrintList $list) {}

    public function download(User $actor, BranchScope $scope): BinaryFileResponse
    {
        $stock = $this->list->build($scope);
        $now = now('Asia/Dhaka');
        $html = view('invoices.stock-list', [
            'company' => $this->company(),
            'branch' => $scope->allBranches ? __('stock_print.all_branches') : (string) ($scope->branch?->name ?? ''),
            'printed_at' => $now,
            'rows' => $stock['rows'],
            'total_quantity' => $stock['total_quantity'],
            'total_value' => $stock['total_value'],
        ])->render();

        $dir = Storage::disk('local')->path('report-exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = 'Madina-stock-list-'.$now->format('Y-m-d').'.pdf';
        $path = $dir.'/stock-list-'.$now->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.pdf';

        try {
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
                '<table width="100%" style="font-size:8pt;color:#64748b"><tr><td>'.e(__('stock_print.footer', ['date' => LocalDigits::text($now->format('d/m/Y'))])).'</td>'
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
            'branch' => $scope->allBranches ? 'all' : ($scope->branch?->code ?? ''),
            'rows' => count($stock['rows']),
        ]);

        return response()->download($path, $filename, ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }

    /**
     * The letterhead as the owner set it in the website editor.
     *
     * @return array{name: string, address: string, phones: string, logo_path: ?string}
     */
    private function company(): array
    {
        $custom = SiteCustomization::all();
        $defaults = SiteCustomization::defaults();
        $letterhead = static fn (string $key): string => filled($custom[$key] ?? null) ? (string) $custom[$key] : (string) ($defaults[$key] ?? '');
        $logoPath = SiteCustomization::logoFilePath() ?? public_path('branding/madina-logo-latest.png');
        if (! is_file($logoPath)) {
            $logoPath = public_path('madina-header-logo.png');
        }

        return [
            'name' => $letterhead('contact_company_title'),
            'address' => $letterhead('office_address'),
            'phones' => $letterhead('contact_phone'),
            'logo_path' => is_file($logoPath) ? $logoPath : null,
        ];
    }
}
